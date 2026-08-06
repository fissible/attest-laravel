<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Import;

/**
 * @api
 */
final readonly class JsonlImportContext
{
    public function __construct(
        public string $importer,
        public int $lineNumber,
        public string $rawLine,
        public string $contentHash,
    ) {
        if ($lineNumber < 1) {
            throw new \InvalidArgumentException('lineNumber must be >= 1');
        }
    }
}
