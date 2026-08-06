<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

use Fissible\Attest\Anchor\AnchorOutcome;
use Fissible\Attest\Bundle\BundleExporter;
use Fissible\Attest\Bundle\BundleReader;
use Fissible\Attest\Bundle\BundleStore;
use Fissible\Attest\Bundle\ChainSegmentMeta;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Headers\HeaderProviderSet;
use Fissible\Attest\Verification\SignatureVerifier;
use Fissible\Attest\Verification\VerificationPolicy;
use Fissible\Attest\Verification\VerificationResult;
use Fissible\Attest\Verification\Verifier;
use Fissible\AttestLaravel\Support\AnchorDriverResolver;
use Fissible\AttestLaravel\Support\HeaderProviderResolver;
use Fissible\AttestLaravel\Support\TrustedKeyResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ParagonIE\ConstantTime\Base64;

/**
 * @internal
 */
final class BundleOperations
{
    /** @var (callable(?string, ?string, ?string): HeaderProviderSet)|null */
    private $headerProviderFactory;

    /**
     * @param (callable(?string, ?string, ?string): HeaderProviderSet)|null $headerProviderFactory
     */
    public function __construct(
        private readonly ChainStore $store,
        private readonly TrustedKeyResolver $trustedKeys,
        private readonly HeaderProviderResolver $headers,
        private readonly AnchorDriverResolver $anchorDrivers,
        private readonly ConfigRepository $config,
        ?callable $headerProviderFactory = null,
    ) {
        $this->headerProviderFactory = $headerProviderFactory;
    }

    /**
     * @param list<string> $claimedKeyFiles
     */
    public function export(
        string $chainId,
        int $fromSeq,
        int $toSeq,
        string $outPath,
        ?string $note = null,
        ?string $issuerHint = null,
        array $claimedKeyFiles = [],
    ): BundleExportResult {
        $exporter = BundleExporter::create($this->store)
            ->forChainSegment($chainId, $fromSeq, $toSeq);

        foreach ($claimedKeyFiles as $path) {
            $exporter->withClaimedKey(
                $this->readClaimedKeyFile($path),
                keyId: $this->keyIdFromPath($path),
            );
        }

        if ($note !== null && $note !== '') {
            $exporter->withNote($note);
        }

        if ($issuerHint !== null && $issuerHint !== '') {
            $exporter->withIssuerHint($issuerHint);
        }

        $exporter->writeTo($outPath);
        $bytesWritten = filesize($outPath);

        return new BundleExportResult(
            outPath: $outPath,
            bytesWritten: is_int($bytesWritten) ? $bytesWritten : 0,
            chainId: $chainId,
            fromSeq: $fromSeq,
            toSeq: $toSeq,
            envelopeCount: $toSeq - $fromSeq + 1,
            warnings: $exporter->warnings(),
        );
    }

    /**
     * @param list<string> $trustedKeys
     * @param list<string> $trustedKeyFiles
     */
    public function verify(
        string $bundlePath,
        ?string $chainId = null,
        ?string $minAnchor = null,
        array $trustedKeys = [],
        array $trustedKeyFiles = [],
        bool $allowUntrusted = false,
        bool $allowProviderDisagreement = false,
        ?string $bitcoinCoreRpc = null,
        ?string $bitcoinCoreCookie = null,
        ?string $esploraUrl = null,
    ): BundleVerifyResult {
        unset($allowUntrusted);

        $reader = BundleReader::open($bundlePath);
        try {
            $segment = $this->resolveSegment($reader, $chainId);
            $trusted = $this->trustedKeys->resolve($trustedKeys, $trustedKeyFiles);
            $headers = $this->resolveHeaders($bitcoinCoreRpc, $bitcoinCoreCookie, $esploraUrl);
            $proofEnvelopes = iterator_to_array($reader->readProofEnvelopes($segment->chainId), false);

            $verifier = new Verifier(
                store: new BundleStore($reader),
                signatures: new SignatureVerifier($trusted),
                policy: new VerificationPolicy(
                    minAnchorOutcome: $this->parseMinAnchor($minAnchor ?? $this->configuredMinAnchor()),
                    allowProviderDisagreement: $allowProviderDisagreement || $this->configuredAllowProviderDisagreement(),
                    requireTrustedKey: $this->configuredRequireTrustedKey(),
                ),
                anchorDrivers: $this->anchorDrivers->verificationDrivers(),
                headers: $headers,
                detachedAnchorEnvelopes: $proofEnvelopes,
            );

            $verification = $verifier->verifyChain($segment->chainId, $segment->fromSeq, $segment->toSeq);
            $readerWarnings = $reader->warnings();
            if ($readerWarnings !== []) {
                $verification = $this->withWarnings($verification, $readerWarnings);
            }

            return new BundleVerifyResult(
                bundlePath: $bundlePath,
                chainId: $segment->chainId,
                fromSeq: $segment->fromSeq,
                toSeq: $segment->toSeq,
                verification: $verification,
            );
        } finally {
            $reader->close();
        }
    }

    private function readClaimedKeyFile(string $path): string
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException('Claimed key file not found: ' . $path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException('Could not read claimed key file: ' . $path);
        }

        try {
            return Base64::decode(trim($contents), strictPadding: true);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Claimed key file must contain strict base64', previous: $e);
        }
    }

    private function keyIdFromPath(string $path): ?string
    {
        $keyId = pathinfo($path, PATHINFO_FILENAME);

        return is_string($keyId) && $keyId !== '' ? $keyId : null;
    }

    private function resolveSegment(BundleReader $reader, ?string $chainId): ChainSegmentMeta
    {
        $chains = $reader->manifest()->chains;
        if ($chains === []) {
            throw new \RuntimeException('Bundle manifest contains no chain segments');
        }

        if ($chainId === null || $chainId === '') {
            return $chains[0];
        }

        foreach ($chains as $segment) {
            if ($segment->chainId === $chainId) {
                return $segment;
            }
        }

        throw new \InvalidArgumentException("Chain '{$chainId}' not found in bundle manifest");
    }

    private function resolveHeaders(
        ?string $bitcoinCoreRpc,
        ?string $bitcoinCoreCookie,
        ?string $esploraUrl,
    ): HeaderProviderSet {
        $factory = $this->headerProviderFactory;
        if ($factory !== null) {
            return $factory($bitcoinCoreRpc, $bitcoinCoreCookie, $esploraUrl);
        }

        return $this->headers->resolve($bitcoinCoreRpc, $bitcoinCoreCookie, $esploraUrl);
    }

    /**
     * @param list<\Fissible\Attest\Verification\Warning> $warnings
     */
    private function withWarnings(VerificationResult $result, array $warnings): VerificationResult
    {
        return new VerificationResult(
            outcome: $result->outcome,
            chainStats: $result->chainStats,
            warnings: [...$result->warnings, ...$warnings],
            brokenAtSeq: $result->brokenAtSeq,
            message: $result->message,
            signatureResults: $result->signatureResults,
            anchorVerification: $result->anchorVerification,
        );
    }

    private function configuredMinAnchor(): ?string
    {
        $configured = $this->config->get('attest.verification.min_anchor_outcome');

        return is_string($configured) && trim($configured) !== '' ? $configured : null;
    }

    private function configuredRequireTrustedKey(): bool
    {
        return $this->configBool('attest.verification.require_trusted_key', true);
    }

    private function configuredAllowProviderDisagreement(): bool
    {
        return $this->configBool('attest.verification.allow_provider_disagreement', false);
    }

    private function configBool(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            return $parsed ?? $default;
        }

        return $default;
    }

    private function parseMinAnchor(?string $raw): ?AnchorOutcome
    {
        if ($raw === null) {
            return null;
        }

        return match (strtolower(trim($raw))) {
            '' => null,
            'local_only' => AnchorOutcome::LOCAL_ONLY,
            'pending' => AnchorOutcome::PENDING,
            'upgraded_no_headers' => AnchorOutcome::UPGRADED_NO_HEADERS,
            'remote_header_confirmed' => AnchorOutcome::REMOTE_HEADER_CONFIRMED,
            'bitcoin_verified' => AnchorOutcome::BITCOIN_VERIFIED,
            default => throw new \InvalidArgumentException(
                "Invalid min anchor value: {$raw} (allowed: local_only, pending, upgraded_no_headers, remote_header_confirmed, bitcoin_verified)",
            ),
        };
    }
}
