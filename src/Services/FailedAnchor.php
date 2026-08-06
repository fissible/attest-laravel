<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

/**
 * @internal
 */
final readonly class FailedAnchor
{
    public function __construct(
        public string $anchorId,
        public ?string $envelopeId,
        public string $error,
    ) {
    }
}
