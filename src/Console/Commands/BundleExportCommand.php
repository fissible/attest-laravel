<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Console\Commands;

use Fissible\Attest\Bundle\BundleExportException;
use Fissible\AttestLaravel\Services\BundleExportResult;
use Fissible\AttestLaravel\Services\BundleOperations;
use Fissible\AttestLaravel\Support\CommandJson;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class BundleExportCommand extends Command
{
    protected $signature = 'attest:bundle:export
        {--chain= : Chain ID}
        {--from= : First sequence number}
        {--to= : Last sequence number}
        {--out= : Output bundle path}
        {--note= : Manifest note}
        {--issuer-hint= : Manifest issuer hint}
        {--include-claimed-key=* : Path to base64 pubkey}
        {--json : Emit JSON}';

    protected $description = 'Export an attest chain segment to a portable bundle.';

    public function handle(BundleOperations $bundles): int
    {
        $chainId = $this->chainId();
        if ($chainId === null) {
            return $this->failCommand('error: --chain is required or attest.anchoring.default_chain must be configured');
        }

        $fromSeq = $this->integerOption('from', minimum: 1);
        if ($fromSeq === null) {
            return $this->failCommand('error: --from must be an integer >= 1');
        }

        $toSeq = $this->integerOption('to', minimum: 1);
        if ($toSeq === null) {
            return $this->failCommand('error: --to must be an integer >= 1');
        }

        if ($toSeq < $fromSeq) {
            return $this->failCommand('error: --to must be >= --from');
        }

        $outPath = $this->nullableStringOption('out');
        if ($outPath === null) {
            return $this->failCommand('error: --out is required and must not be empty');
        }

        try {
            $result = $bundles->export(
                chainId: $chainId,
                fromSeq: $fromSeq,
                toSeq: $toSeq,
                outPath: $outPath,
                note: $this->nullableStringOption('note'),
                issuerHint: $this->nullableStringOption('issuer-hint'),
                claimedKeyFiles: $this->stringListOption('include-claimed-key'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->failCommand('error: ' . $e->getMessage());
        } catch (BundleExportException $e) {
            return $this->failCommand('error: ' . $e->getMessage(), 4);
        }

        $this->writeResult($result);

        return self::SUCCESS;
    }

    private function writeResult(BundleExportResult $result): void
    {
        if ((bool) $this->option('json')) {
            CommandJson::print($this->output, [
                'format_version' => 'attest.cli.export.v1',
                'command' => 'bundle:export',
                'out' => $result->outPath,
                'bytes_written' => $result->bytesWritten,
                'chain_segments' => [
                    [
                        'chain_id' => $result->chainId,
                        'from_seq' => $result->fromSeq,
                        'to_seq' => $result->toSeq,
                        'envelope_count' => $result->envelopeCount,
                    ],
                ],
                'anchors' => [],
                'warnings' => CommandJson::warningList($result->warnings),
            ]);

            return;
        }

        $this->line(sprintf('bundle exported to %s (%d bytes)', $result->outPath, $result->bytesWritten));
        foreach ($result->warnings as $warning) {
            $this->warn('[' . $warning->code . '] ' . $warning->message);
        }
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
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $raw = (string) $value;
        if (! ctype_digit($raw)) {
            return null;
        }

        $parsed = (int) $raw;

        return $parsed >= $minimum ? $parsed : null;
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
