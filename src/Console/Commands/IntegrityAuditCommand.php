<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Console\Commands;

use Fissible\AttestLaravel\Services\IntegrityAudit;
use Fissible\AttestLaravel\Services\IntegrityAuditResult;
use Fissible\AttestLaravel\Services\IntegrityDrift;
use Fissible\AttestLaravel\Support\CommandJson;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * @internal
 */
final class IntegrityAuditCommand extends Command
{
    protected $signature = 'attest:integrity:audit
        {--chain= : Chain ID}
        {--from=1 : First sequence number}
        {--to= : Last sequence number}
        {--json : Emit JSON}';

    protected $description = 'Audit Eloquent attest index columns against raw canonical envelopes.';

    public function handle(IntegrityAudit $audit): int
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

        try {
            $result = $audit->audit($chainId, $fromSeq, is_int($toSeq) ? $toSeq : null);
        } catch (\InvalidArgumentException $e) {
            return $this->failCommand('error: ' . $e->getMessage());
        }

        $exitCode = $result->drifts === [] ? self::SUCCESS : 4;
        $this->writeResult($result, $exitCode);

        return $exitCode;
    }

    private function writeResult(IntegrityAuditResult $result, int $exitCode): void
    {
        if ((bool) $this->option('json')) {
            CommandJson::print($this->output, [
                'format_version' => 'attest.laravel.integrity-audit.v1',
                'command' => 'integrity:audit',
                'result' => $result->drifts === [] ? 'clean' : 'drift_detected',
                'exit_code' => $exitCode,
                'chain_id' => $result->chainId,
                'from_seq' => $result->fromSeq,
                'to_seq' => $result->toSeq,
                'checked_count' => $result->checkedCount,
                'drift_count' => count($result->drifts),
                'drifts' => array_map(
                    static fn (IntegrityDrift $drift): array => [
                        'sequence' => $drift->sequence,
                        'column' => $drift->column,
                        'stored' => $drift->stored,
                        'computed' => $drift->computed,
                    ],
                    $result->drifts,
                ),
            ]);

            return;
        }

        if ($result->drifts === []) {
            $this->line(sprintf(
                'integrity audit: clean (%d envelopes checked)',
                $result->checkedCount,
            ));
            $this->line('exit ' . $exitCode);

            return;
        }

        $this->warn(sprintf(
            'integrity audit: drift detected (%d drift%s across %d envelopes checked)',
            count($result->drifts),
            count($result->drifts) === 1 ? '' : 's',
            $result->checkedCount,
        ));

        foreach ($result->drifts as $drift) {
            $this->warn(sprintf(
                'seq %d %s: stored=%s computed=%s',
                $drift->sequence,
                $drift->column,
                $this->stringify($drift->stored),
                $this->stringify($drift->computed),
            ));
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

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function failCommand(string $message, int $exitCode = self::FAILURE): int
    {
        $this->error($message);

        return $exitCode;
    }
}
