<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Console\Commands;

use Fissible\Attest\Verification\VerificationResult;
use Fissible\AttestLaravel\Services\VerifyChain;
use Fissible\AttestLaravel\Support\CommandJson;
use Fissible\AttestLaravel\Support\VerificationExitCode;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * @internal
 */
final class VerifyCommand extends Command
{
    protected $signature = 'attest:verify
        {--chain= : Chain ID}
        {--from=1 : First sequence number}
        {--to= : Last sequence number}
        {--trusted-key=* : <key_id>=<base64-pubkey>}
        {--trusted-key-file=* : Path or <key_id>=path to base64 pubkey}
        {--min-anchor= : local_only|pending|upgraded_no_headers|remote_header_confirmed|bitcoin_verified}
        {--allow-provider-disagreement : Allow strongest passing provider outcome}
        {--allow-untrusted : Exit 0 for integrity-verified-untrusted}
        {--bitcoin-core-rpc= : Bitcoin Core RPC URL}
        {--bitcoin-core-cookie= : Bitcoin Core cookie file}
        {--esplora-url= : Esplora base URL}
        {--json : Emit JSON}';

    protected $description = 'Verify an attest chain against signatures, anchors, and optional Bitcoin header providers.';

    public function handle(VerifyChain $verify): int
    {
        $chainId = $this->chainId();
        if ($chainId === null) {
            return $this->failCommand('error: --chain is required or attest.anchoring.default_chain must be configured');
        }

        $fromSeq = $this->integerOption('from', minimum: 1);
        if ($fromSeq === null) {
            return $this->failCommand('error: --from must be an integer >= 1');
        }

        $toSeq = $this->nullableIntegerOption('to', minimum: 1);
        if ($toSeq === false) {
            return $this->failCommand('error: --to must be an integer >= 1');
        }

        if (is_int($toSeq) && $toSeq < $fromSeq) {
            return $this->failCommand('error: --to must be >= --from');
        }

        $allowUntrusted = (bool) $this->option('allow-untrusted');

        try {
            $result = $verify->verify(
                chainId: $chainId,
                fromSeq: $fromSeq,
                toSeq: is_int($toSeq) ? $toSeq : null,
                minAnchor: $this->nullableStringOption('min-anchor'),
                trustedKeys: $this->stringListOption('trusted-key'),
                trustedKeyFiles: $this->stringListOption('trusted-key-file'),
                allowUntrusted: $allowUntrusted,
                allowProviderDisagreement: (bool) $this->option('allow-provider-disagreement'),
                bitcoinCoreRpc: $this->nullableStringOption('bitcoin-core-rpc'),
                bitcoinCoreCookie: $this->nullableStringOption('bitcoin-core-cookie'),
                esploraUrl: $this->nullableStringOption('esplora-url'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->failCommand('error: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            return $this->failCommand('error: ' . $e->getMessage());
        }

        $exitCode = VerificationExitCode::forOutcome($result->outcome, allowUntrusted: $allowUntrusted);
        $this->writeResult($result, $exitCode);

        return $exitCode;
    }

    private function writeResult(VerificationResult $result, int $exitCode): void
    {
        if ((bool) $this->option('json')) {
            CommandJson::print($this->output, CommandJson::verification('verify', $result, $exitCode));

            return;
        }

        $stats = $result->chainStats;
        $this->line(sprintf(
            'chain & signatures: %s (%d envelopes, %d trusted, %d untrusted)',
            $result->outcome->value,
            $stats->envelopeCount,
            $stats->trustedSignatureCount,
            $stats->untrustedSignatureCount,
        ));

        if ($result->anchorVerification !== null) {
            $this->line('anchor: ' . $result->anchorVerification->outcome->value);
        }

        foreach ($result->warnings as $warning) {
            $this->warn($warning->message);
        }

        $this->line('exit ' . $exitCode);
    }

    private function chainId(): ?string
    {
        return $this->nullableStringOption('chain')
            ?? $this->nullableConfigString('attest.anchoring.default_chain');
    }

    private function nullableStringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableConfigString(string $key): ?string
    {
        $config = $this->getLaravel()->make(ConfigRepository::class);
        assert($config instanceof ConfigRepository);

        $value = $config->get($key);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function integerOption(string $name, int $minimum): ?int
    {
        $parsed = $this->nullableIntegerOption($name, $minimum);

        return is_int($parsed) ? $parsed : null;
    }

    private function nullableIntegerOption(string $name, int $minimum): int|false|null
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $raw = (string) $value;
        if (! ctype_digit($raw)) {
            return false;
        }

        $parsed = (int) $raw;

        return $parsed >= $minimum ? $parsed : false;
    }

    /**
     * @return list<string>
     */
    private function stringListOption(string $name): array
    {
        $values = $this->option($name);
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    private function failCommand(string $message, int $exitCode = self::FAILURE): int
    {
        $this->error($message);

        return $exitCode;
    }
}
