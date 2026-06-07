<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

use Fissible\Attest\Envelope\EnvelopeCodec;
use Fissible\AttestLaravel\Support\Timestamp;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

final class IntegrityAudit
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ConfigRepository $config,
    ) {
    }

    public function audit(string $chainId, int $fromSeq = 1, ?int $toSeq = null): IntegrityAuditResult
    {
        if ($fromSeq < 1) {
            throw new \InvalidArgumentException('fromSeq must be >= 1');
        }
        if ($toSeq !== null && $toSeq < $fromSeq) {
            throw new \InvalidArgumentException('toSeq must be >= fromSeq');
        }

        $checked = 0;
        $drifts = [];

        $query = $this->connection()->table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->where('sequence', '>=', $fromSeq)
            ->when($toSeq !== null, fn ($q) => $q->where('sequence', '<=', $toSeq))
            ->orderBy('sequence');

        foreach ($query->cursor() as $row) {
            $checked++;
            $signed = EnvelopeCodec::decodeSigned((string) $row->raw_envelope);
            $rowSequence = (int) $row->sequence;

            $this->compare($drifts, $rowSequence, 'sequence', $rowSequence, $signed->envelope->seq);
            $this->compare($drifts, $rowSequence, 'envelope_id', (string) $row->envelope_id, $signed->envelope->id);
            $this->compare($drifts, $rowSequence, 'prev_hash', $row->prev_hash, $signed->envelope->prevHash);
            $this->compare($drifts, $rowSequence, 'self_hash', (string) $row->self_hash, $signed->selfHash());
            $this->compare($drifts, $rowSequence, 'key_id', (string) $row->key_id, $signed->envelope->keyId);
            $this->compare($drifts, $rowSequence, 'type', (string) $row->type, $signed->envelope->type);
            $this->compare(
                $drifts,
                $rowSequence,
                'created_at',
                $this->normalizeStoredTimestamp($row->created_at),
                Timestamp::fromEnvelopeTs($signed->envelope->ts),
            );
        }

        return new IntegrityAuditResult(
            chainId: $chainId,
            fromSeq: $fromSeq,
            toSeq: $toSeq,
            checkedCount: $checked,
            drifts: $drifts,
        );
    }

    /**
     * @param list<IntegrityDrift> $drifts
     */
    private function compare(array &$drifts, int $sequence, string $column, mixed $stored, mixed $computed): void
    {
        if ($stored === $computed) {
            return;
        }

        $drifts[] = new IntegrityDrift(
            sequence: $sequence,
            column: $column,
            stored: $stored,
            computed: $computed,
        );
    }

    private function normalizeStoredTimestamp(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return Timestamp::format($value);
        }

        $raw = (string) $value;
        try {
            return Timestamp::format(new \DateTimeImmutable($raw, new \DateTimeZone('UTC')));
        } catch (\Throwable) {
            return $raw;
        }
    }

    private function connection(): ConnectionInterface
    {
        $name = $this->config->get('attest.connection') ?? $this->config->get('database.default');
        $connection = $this->db->connection(is_string($name) ? $name : null);
        assert($connection instanceof ConnectionInterface);

        return $connection;
    }
}
