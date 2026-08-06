<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

use Fissible\Attest\Verification\Warning;

/**
 * @internal
 */
final readonly class BundleExportResult
{
    /**
     * @param list<Warning> $warnings
     */
    public function __construct(
        public string $outPath,
        public int $bytesWritten,
        public string $chainId,
        public int $fromSeq,
        public int $toSeq,
        public int $envelopeCount,
        public array $warnings = [],
    ) {
    }
}
