<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

/**
 * @internal
 */
final readonly class IntegrityDrift
{
    public function __construct(
        public int $sequence,
        public string $column,
        public mixed $stored,
        public mixed $computed,
    ) {
    }
}
