<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Console\Commands;

use Fissible\AttestLaravel\Services\UpgradePendingAnchors;
use Fissible\AttestLaravel\Services\UpgradeRunResult;
use Fissible\AttestLaravel\Support\CommandJson;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class UpgradeCommand extends Command
{
    protected $signature = 'attest:upgrade
        {--chain= : Chain ID}
        {--anchor-id= : Upgrade a single anchor id}
        {--all-pending : Sweep all pending OTS anchors}
        {--calendar-url=* : OpenTimestamps calendar URL}
        {--json : Emit JSON}';

    protected $description = 'Upgrade pending OpenTimestamps anchor receipts.';

    public function handle(UpgradePendingAnchors $upgrades): int
    {
        $chainId = $this->chainId();
        if ($chainId === null) {
            return $this->failCommand('error: --chain is required or attest.anchoring.default_chain must be configured');
        }

        $anchorId = $this->nullableStringOption('anchor-id');
        $allPending = (bool) $this->option('all-pending');

        if ($anchorId !== null && $allPending) {
            return $this->failCommand('error: --anchor-id and --all-pending are mutually exclusive');
        }

        if ($anchorId === null && ! $allPending) {
            return $this->failCommand('error: one of --anchor-id or --all-pending is required');
        }

        try {
            $result = $anchorId !== null
                ? $upgrades->upgradeOne($chainId, $anchorId, $this->calendarUrls())
                : $upgrades->upgradeAllPending($chainId, $this->calendarUrls());
        } catch (\RuntimeException $e) {
            return $this->failCommand('error: driver error: ' . $e->getMessage(), 4);
        }

        $this->writeResult($result);

        return $anchorId !== null && $result->failed !== []
            ? 4
            : self::SUCCESS;
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

    /**
     * @return list<string>
     */
    private function calendarUrls(): array
    {
        $urls = [];
        $option = $this->option('calendar-url');
        if (! is_array($option)) {
            return [];
        }

        foreach ($option as $url) {
            if (! is_string($url)) {
                continue;
            }
            $url = trim($url);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function writeResult(UpgradeRunResult $result): void
    {
        if ((bool) $this->option('json')) {
            CommandJson::print($this->output, [
                'format_version' => 'attest.cli.upgrade.v1',
                'command' => 'upgrade',
                'upgraded' => array_map(
                    static fn ($anchor): array => [
                        'anchor_id' => $anchor->anchorId,
                        'previous_envelope_id' => $anchor->previousEnvelopeId,
                        'new_envelope_id' => $anchor->newEnvelopeId,
                    ],
                    $result->upgraded,
                ),
                'unchanged' => array_map(
                    static fn ($anchor): array => [
                        'anchor_id' => $anchor->anchorId,
                        'envelope_id' => $anchor->envelopeId,
                        'state' => $anchor->state,
                    ],
                    $result->unchanged,
                ),
                'failed' => array_map(
                    static fn ($anchor): array => [
                        'anchor_id' => $anchor->anchorId,
                        'envelope_id' => $anchor->envelopeId,
                        'error' => $anchor->error,
                    ],
                    $result->failed,
                ),
                'warnings' => $result->warnings,
            ]);

            return;
        }

        $this->line(sprintf(
            'upgraded %d, unchanged %d, failed %d',
            count($result->upgraded),
            count($result->unchanged),
            count($result->failed),
        ));

        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }

        foreach ($result->failed as $failed) {
            $this->error(sprintf('failed %s: %s', $failed->anchorId, $failed->error));
        }
    }

    private function failCommand(string $message, int $exitCode = self::FAILURE): int
    {
        $this->error($message);

        return $exitCode;
    }
}
