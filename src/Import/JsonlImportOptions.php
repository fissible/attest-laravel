<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Import;

/**
 * @api
 */
final readonly class JsonlImportOptions
{
    public function __construct(
        public bool $continueOnError = false,
        public bool $skipBlankLines = true,
    ) {
    }
}
