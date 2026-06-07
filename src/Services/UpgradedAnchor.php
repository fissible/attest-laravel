<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

final readonly class UpgradedAnchor
{
    public function __construct(
        public string $anchorId,
        public ?string $previousEnvelopeId,
        public string $newEnvelopeId,
    ) {
    }
}
