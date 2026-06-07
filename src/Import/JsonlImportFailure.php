<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Import;

final readonly class JsonlImportFailure
{
    public function __construct(
        public int $lineNumber,
        public string $message,
        public string $exceptionClass,
    ) {
        if ($lineNumber < 1) {
            throw new \InvalidArgumentException('lineNumber must be >= 1');
        }
    }

    public static function fromThrowable(int $lineNumber, \Throwable $throwable): self
    {
        return new self(
            lineNumber: $lineNumber,
            message: $throwable->getMessage(),
            exceptionClass: $throwable::class,
        );
    }
}
