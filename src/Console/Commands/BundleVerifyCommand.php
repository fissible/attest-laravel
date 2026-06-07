<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Console\Commands;

use Fissible\Attest\Bundle\InvalidBundle;
use Fissible\AttestLaravel\Services\BundleOperations;
use Fissible\AttestLaravel\Services\BundleVerifyResult;
use Fissible\AttestLaravel\Support\CommandJson;
use Fissible\AttestLaravel\Support\VerificationExitCode;
use Illuminate\Console\Command;

final class BundleVerifyCommand extends Command
{
    protected $signature = 'attest:bundle:verify
        {--bundle= : Bundle path}
        {--chain= : Chain ID, defaults to first manifest chain}
        {--trusted-key=* : <key_id>=<base64-pubkey>}
        {--trusted-key-file=* : Path or <key_id>=path to base64 pubkey}
        {--min-anchor= : Anchor threshold}
        {--allow-provider-disagreement : Allow strongest passing provider outcome}
        {--allow-untrusted : Exit 0 for integrity-verified-untrusted}
        {--bitcoin-core-rpc= : Bitcoin Core RPC URL}
        {--bitcoin-core-cookie= : Bitcoin Core cookie file}
        {--esplora-url= : Esplora base URL}
        {--json : Emit JSON}';

    protected $description = 'Verify a portable attest bundle.';

    public function handle(BundleOperations $bundles): int
    {
        $bundlePath = $this->nullableStringOption('bundle');
        if ($bundlePath === null) {
            return $this->failCommand('error: --bundle is required and must not be empty');
        }

        if (! is_file($bundlePath)) {
            return $this->failCommand('error: --bundle path does not exist or is not a file: ' . $bundlePath);
        }

        $allowUntrusted = (bool) $this->option('allow-untrusted');

        try {
            $result = $bundles->verify(
                bundlePath: $bundlePath,
                chainId: $this->nullableStringOption('chain'),
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
        } catch (InvalidBundle $e) {
            return $this->failCommand('error: ' . $e->getMessage(), 4);
        } catch (\RuntimeException $e) {
            return $this->failCommand('error: ' . $e->getMessage(), 4);
        }

        $exitCode = VerificationExitCode::forOutcome($result->verification->outcome, allowUntrusted: $allowUntrusted);
        $this->writeResult($result, $exitCode);

        return $exitCode;
    }

    private function writeResult(BundleVerifyResult $result, int $exitCode): void
    {
        if ((bool) $this->option('json')) {
            CommandJson::print($this->output, CommandJson::verification('bundle:verify', $result->verification, $exitCode));

            return;
        }

        $this->line(sprintf(
            'bundle: %s (chain: %s, seqs %d-%d)',
            $result->bundlePath,
            $result->chainId,
            $result->fromSeq,
            $result->toSeq,
        ));

        $stats = $result->verification->chainStats;
        $this->line(sprintf(
            'chain & signatures: %s (%d envelopes, %d trusted, %d untrusted)',
            $result->verification->outcome->value,
            $stats->envelopeCount,
            $stats->trustedSignatureCount,
            $stats->untrustedSignatureCount,
        ));

        if ($result->verification->anchorVerification !== null) {
            $this->line('anchor: ' . $result->verification->anchorVerification->outcome->value);
        }

        foreach ($result->verification->warnings as $warning) {
            $this->warn($warning->message);
        }

        $this->line('exit ' . $exitCode);
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
