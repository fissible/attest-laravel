<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Import;

final readonly class JsonlImportResult
{
    /**
     * @param list<string> $envelopeIds
     * @param list<JsonlImportFailure> $failures
     */
    public function __construct(
        public int $linesRead,
        public int $parsed,
        public int $imported,
        public int $skipped,
        public int $alreadyImported,
        public int $failed,
        public int $lastLineNumber,
        public array $envelopeIds = [],
        public array $failures = [],
    ) {
        foreach ([
            'linesRead' => $linesRead,
            'parsed' => $parsed,
            'imported' => $imported,
            'skipped' => $skipped,
            'alreadyImported' => $alreadyImported,
            'failed' => $failed,
            'lastLineNumber' => $lastLineNumber,
        ] as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException("$name must be >= 0");
            }
        }
    }
}
