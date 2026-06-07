<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Import;

class JsonlImportException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $lineNumber = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
