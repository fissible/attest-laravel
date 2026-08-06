<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

/**
 * @internal
 */
final readonly class IntegrityAuditResult
{
    /**
     * @param list<IntegrityDrift> $drifts
     */
    public function __construct(
        public string $chainId,
        public int $fromSeq,
        public ?int $toSeq,
        public int $checkedCount,
        public array $drifts = [],
    ) {
    }
}
