<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

/**
 * @internal
 */
final readonly class UpgradedAnchor
{
    public function __construct(
        public string $anchorId,
        public ?string $previousEnvelopeId,
        public string $newEnvelopeId,
    ) {
    }
}
