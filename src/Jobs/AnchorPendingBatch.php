<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Jobs;

use Fissible\Attest\Chain\ChainStore;
use Fissible\AttestLaravel\Services\AnchorRangeRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * @api
 */
final class AnchorPendingBatch implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    /**
     * @param list<string> $calendarUrls
     */
    public function __construct(
        public readonly string $chainId,
        public readonly int $fromSeq = 1,
        public readonly ?int $toSeq = null,
        public readonly ?string $driver = null,
        public readonly array $calendarUrls = [],
        public readonly ?int $minCalendars = null,
    ) {
    }

    public function handle(AnchorRangeRunner $runner, ChainStore $store): void
    {
        $toSeq = $this->toSeq;
        if ($toSeq === null) {
            $tail = $store->tail($this->chainId);
            if ($tail === null) {
                return;
            }
            $toSeq = $tail->envelope->seq;
        }

        if ($toSeq < $this->fromSeq) {
            return;
        }

        $runner->anchorRange(
            chainId: $this->chainId,
            fromSeq: $this->fromSeq,
            toSeq: $toSeq,
            driverName: $this->driver,
            calendarUrls: $this->calendarUrls,
            minCalendars: $this->minCalendars,
        );
    }
}
