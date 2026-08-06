<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

use Fissible\Attest\Verification\Warning;

/**
 * @internal
 */
final readonly class AnchorRunResult
{
    public const ANCHORED = 'anchored';
    public const RECONCILED = 'reconciled';
    public const SKIPPED = 'skipped';
    public const NO_STATE = 'none';

    /**
     * @param list<Warning> $warnings
     */
    public function __construct(
        public string $result,
        public ?string $anchorId,
        public ?string $envelopeId,
        public string $driver,
        public string $state,
        public string $chainId,
        public int $fromSeq,
        public int $toSeq,
        public array $warnings = [],
    ) {
    }
}
