<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Console\Commands;

use Fissible\Attest\Anchor\OpenTimestamps\CalendarUnavailable;
use Fissible\Attest\Anchor\NullDriver;
use Fissible\Attest\Anchor\OpenTimestampsDriver;
use Fissible\Attest\Chain\ChainStore;
use Fissible\AttestLaravel\Jobs\AnchorPendingBatch;
use Fissible\AttestLaravel\Services\AnchorRangeRunner;
use Fissible\AttestLaravel\Services\AnchorRunResult;
use Fissible\AttestLaravel\Support\CommandJson;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class AnchorCommand extends Command
{
    protected $signature = 'attest:anchor
        {--chain= : Chain ID}
        {--from=1 : First sequence number}
        {--to= : Last sequence number. Defaults to current tail when omitted.}
        {--driver= : Anchor driver, local-only or opentimestamps}
        {--calendar-url=* : OpenTimestamps calendar URL}
        {--min-calendars= : Minimum calendars required for OTS}
        {--sync : Run immediately instead of dispatching AnchorPendingBatch}
        {--queue= : Queue name override}
        {--connection= : Queue connection override}
        {--json : Emit JSON}';

    protected $description = 'Anchor an attest chain range or dispatch a queued anchoring job.';

    public function handle(): int
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

        $minCalendars = $this->nullableIntegerOption('min-calendars', minimum: 1);
        if ($minCalendars === false) {
            return $this->failCommand('error: --min-calendars must be an integer >= 1');
        }

        $driver = $this->driverOption();
        if ($driver === false) {
            return $this->failCommand('error: --driver must be local-only or opentimestamps');
        }
        $calendarUrls = $this->calendarUrls();

        if ((bool) $this->option('sync')) {
            return $this->runSync($chainId, $fromSeq, $toSeq, $driver, $calendarUrls, $minCalendars);
        }

        return $this->dispatchJob($chainId, $fromSeq, $toSeq, $driver, $calendarUrls, $minCalendars);
    }

    /**
     * @param int|false|null $toSeq
     * @param list<string> $calendarUrls
     */
    private function runSync(
        string $chainId,
        int $fromSeq,
        int|false|null $toSeq,
        ?string $driver,
        array $calendarUrls,
        int|false|null $minCalendars,
    ): int {
        assert($toSeq === null || is_int($toSeq));
        assert($minCalendars === null || is_int($minCalendars));

        $store = $this->getLaravel()->make(ChainStore::class);
        assert($store instanceof ChainStore);

        if ($toSeq === null) {
            $tail = $store->tail($chainId);
            if ($tail === null) {
                return $this->writeSyncNoop($chainId, $fromSeq, $driver);
            }
            $toSeq = $tail->envelope->seq;
        }

        if ($toSeq < $fromSeq) {
            return $this->writeSyncNoop($chainId, $fromSeq, $driver, $toSeq);
        }

        try {
            $runner = $this->getLaravel()->make(AnchorRangeRunner::class);
            assert($runner instanceof AnchorRangeRunner);

            $result = $runner->anchorRange(
                chainId: $chainId,
                fromSeq: $fromSeq,
                toSeq: $toSeq,
                driverName: $driver,
                calendarUrls: $calendarUrls,
                minCalendars: $minCalendars,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->failCommand('error: ' . $e->getMessage());
        } catch (CalendarUnavailable $e) {
            return $this->failCommand('error: calendar unavailable: ' . $e->getMessage(), 4);
        } catch (\RuntimeException $e) {
            return $this->failCommand('error: driver error: ' . $e->getMessage(), 4);
        }

        $this->writeSyncResult($result);

        return self::SUCCESS;
    }

    /**
     * @param int|false|null $toSeq
     * @param list<string> $calendarUrls
     */
    private function dispatchJob(
        string $chainId,
        int $fromSeq,
        int|false|null $toSeq,
        ?string $driver,
        array $calendarUrls,
        int|false|null $minCalendars,
    ): int {
        assert($toSeq === null || is_int($toSeq));
        assert($minCalendars === null || is_int($minCalendars));

        $queue = $this->nullableStringOption('queue')
            ?? $this->nullableConfigString('attest.anchoring.queue');
        $connection = $this->nullableStringOption('connection')
            ?? $this->nullableConfigString('attest.anchoring.connection');

        $job = new AnchorPendingBatch(
            chainId: $chainId,
            fromSeq: $fromSeq,
            toSeq: $toSeq,
            driver: $driver,
            calendarUrls: $calendarUrls,
            minCalendars: $minCalendars,
        );

        if ($queue !== null) {
            $job->onQueue($queue);
        }
        if ($connection !== null) {
            $job->onConnection($connection);
        }

        $bus = $this->getLaravel()->make(Dispatcher::class);
        assert($bus instanceof Dispatcher);
        $bus->dispatch($job);

        if ((bool) $this->option('json')) {
            CommandJson::print($this->output, [
                'format_version' => 'attest.laravel.anchor-dispatch.v1',
                'command' => 'attest:anchor',
                'job' => AnchorPendingBatch::class,
                'chain' => $chainId,
                'from_seq' => $fromSeq,
                'to_seq' => $toSeq,
                'driver' => $driver,
                'calendar_urls' => $calendarUrls,
                'min_calendars' => $minCalendars,
                'queue' => $queue,
                'connection' => $connection,
            ]);
        } else {
            $range = $toSeq === null
                ? sprintf('%s[%d,tail]', $chainId, $fromSeq)
                : sprintf('%s[%d,%d]', $chainId, $fromSeq, $toSeq);
            $this->line(sprintf('dispatched %s for %s', AnchorPendingBatch::class, $range));
        }

        return self::SUCCESS;
    }

    private function writeSyncNoop(string $chainId, int $fromSeq, ?string $driver, ?int $toSeq = null): int
    {
        if ((bool) $this->option('json')) {
            CommandJson::print($this->output, [
                'format_version' => 'attest.cli.anchor.v1',
                'command' => 'anchor',
                'result' => 'noop',
                'anchor_id' => null,
                'envelope_id' => null,
                'target_chain' => $chainId,
                'from_seq' => $fromSeq,
                'to_seq' => $toSeq,
                'driver' => $driver,
                'state' => null,
                'warnings' => [],
            ]);
        } else {
            $this->line(sprintf('no envelopes to anchor for %s starting at sequence %d', $chainId, $fromSeq));
        }

        return self::SUCCESS;
    }

    private function writeSyncResult(AnchorRunResult $result): void
    {
        if ((bool) $this->option('json')) {
            CommandJson::print($this->output, [
                'format_version' => 'attest.cli.anchor.v1',
                'command' => 'anchor',
                'result' => $result->result,
                'anchor_id' => $result->anchorId,
                'envelope_id' => $result->envelopeId,
                'target_chain' => $result->chainId,
                'from_seq' => $result->fromSeq,
                'to_seq' => $result->toSeq,
                'driver' => $result->driver,
                'state' => $result->state,
                'warnings' => CommandJson::warningList($result->warnings),
            ]);

            return;
        }

        if ($result->result === AnchorRunResult::ANCHORED) {
            $this->line(sprintf(
                'anchored %s[%d,%d] via %s; envelope %s',
                $result->chainId,
                $result->fromSeq,
                $result->toSeq,
                $result->driver,
                $result->envelopeId,
            ));
        } elseif ($result->result === AnchorRunResult::RECONCILED) {
            $this->line(sprintf(
                'reconciled existing anchor envelope %s for %s[%d,%d]',
                $result->envelopeId,
                $result->chainId,
                $result->fromSeq,
                $result->toSeq,
            ));
        } else {
            $this->warn(sprintf(
                'skipped %s[%d,%d]; anchor claim is held by another worker',
                $result->chainId,
                $result->fromSeq,
                $result->toSeq,
            ));
        }

        foreach ($result->warnings as $warning) {
            $this->warn($warning->message);
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

    private function driverOption(): string|false|null
    {
        $driver = $this->nullableStringOption('driver');
        if ($driver === null) {
            return null;
        }

        $driver = strtolower($driver);

        return in_array($driver, [NullDriver::NAME, OpenTimestampsDriver::NAME], true)
            ? $driver
            : false;
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

    private function failCommand(string $message, int $exitCode = self::FAILURE): int
    {
        $this->error($message);

        return $exitCode;
    }
}
