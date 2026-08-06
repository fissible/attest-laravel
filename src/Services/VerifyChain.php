<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

use Fissible\Attest\Anchor\AnchorOutcome;
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

/**
 * @internal
 */
final class VerifyChain
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
     * @param list<string> $trustedKeys
     * @param list<string> $trustedKeyFiles
     */
    public function verify(
        string $chainId,
        int $fromSeq = 1,
        ?int $toSeq = null,
        ?string $minAnchor = null,
        array $trustedKeys = [],
        array $trustedKeyFiles = [],
        bool $allowUntrusted = false,
        bool $allowProviderDisagreement = false,
        ?string $bitcoinCoreRpc = null,
        ?string $bitcoinCoreCookie = null,
        ?string $esploraUrl = null,
    ): VerificationResult {
        unset($allowUntrusted);

        $minAnchorOutcome = $this->parseMinAnchor($minAnchor ?? $this->configuredMinAnchor());
        $trusted = $this->trustedKeys->resolve($trustedKeys, $trustedKeyFiles);
        $headers = $this->resolveHeaders($bitcoinCoreRpc, $bitcoinCoreCookie, $esploraUrl);

        $verifier = new Verifier(
            store: $this->store,
            signatures: new SignatureVerifier($trusted),
            policy: new VerificationPolicy(
                minAnchorOutcome: $minAnchorOutcome,
                allowProviderDisagreement: $allowProviderDisagreement || $this->configuredAllowProviderDisagreement(),
                requireTrustedKey: $this->configuredRequireTrustedKey(),
            ),
            anchorDrivers: $this->anchorDrivers->verificationDrivers(),
            headers: $headers,
        );

        return $verifier->verifyChain($chainId, $fromSeq, $toSeq);
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
