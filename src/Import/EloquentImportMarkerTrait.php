<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Import;

use Fissible\AttestLaravel\Support\Timestamp;
use Illuminate\Database\ConnectionInterface;

trait EloquentImportMarkerTrait
{
    abstract protected function importMarkerConnection(): ConnectionInterface;

    abstract protected function importer(): string;

    protected function hasImported(string $contentHash): bool
    {
        $importer = $this->validateMarkerInput($contentHash);

        return $this->importMarkerConnection()
            ->table('attest_import_markers')
            ->where('importer', $importer)
            ->where('content_hash', $contentHash)
            ->exists();
    }

    protected function markImported(string $contentHash, string $envelopeId): bool
    {
        $importer = $this->validateMarkerInput($contentHash);
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}\z/', $envelopeId)) {
            throw new \InvalidArgumentException('envelopeId must be a 26-character ULID string');
        }

        $inserted = $this->importMarkerConnection()
            ->table('attest_import_markers')
            ->insertOrIgnore([
                'importer' => $importer,
                'content_hash' => $contentHash,
                'envelope_id' => $envelopeId,
                'imported_at' => Timestamp::format(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
            ]);

        return $inserted === 1;
    }

    private function validateMarkerInput(string $contentHash): string
    {
        $importer = $this->importer();
        if ($importer === '') {
            throw new \InvalidArgumentException('importer must not be empty');
        }
        if (strlen($importer) > 191) {
            throw new \InvalidArgumentException('importer must be <= 191 bytes');
        }
        if (! preg_match('/^[a-f0-9]{64}\z/', $contentHash)) {
            throw new \InvalidArgumentException('contentHash must be a lower-case 64-character SHA-256 hex digest');
        }
        return $importer;
    }
}
