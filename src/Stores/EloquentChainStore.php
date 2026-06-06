<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores;

use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\RawChainStore;
use Fissible\Attest\Envelope\EnvelopeCodec;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\AttestLaravel\Stores\Locking\ChainLocker;
use Illuminate\Database\ConnectionInterface;

final class EloquentChainStore implements ChainStore, RawChainStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        /** @phpstan-ignore property.onlyWritten (used by append() in Task 4.10) */
        private readonly ChainLocker $locker,
    ) {
    }

    public function append(string $chainId, callable $buildAndSign): SignedEnvelope
    {
        throw new \LogicException('append() lands in Task 4.10');
    }

    public function tail(string $chainId): ?SignedEnvelope
    {
        $row = $this->connection->table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->orderByDesc('sequence')
            ->limit(1)
            ->value('raw_envelope');
        return $row === null ? null : EnvelopeCodec::decodeSigned((string) $row);
    }

    public function readRange(string $chainId, int $fromSeq, ?int $toSeq = null): iterable
    {
        if ($fromSeq < 1) {
            throw new \InvalidArgumentException('fromSeq must be >= 1');
        }
        if ($toSeq !== null && $toSeq < $fromSeq) {
            throw new \InvalidArgumentException('toSeq must be >= fromSeq');
        }
        $query = $this->connection->table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->where('sequence', '>=', $fromSeq)
            ->when($toSeq !== null, fn ($q) => $q->where('sequence', '<=', $toSeq))
            ->orderBy('sequence');
        foreach ($query->cursor() as $row) {
            yield EnvelopeCodec::decodeSigned((string) $row->raw_envelope);
        }
    }

    public function readRawRange(string $chainId, int $fromSeq, ?int $toSeq = null): iterable
    {
        if ($fromSeq < 1) {
            throw new \InvalidArgumentException('fromSeq must be >= 1');
        }
        if ($toSeq !== null && $toSeq < $fromSeq) {
            throw new \InvalidArgumentException('toSeq must be >= fromSeq');
        }
        $query = $this->connection->table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->where('sequence', '>=', $fromSeq)
            ->when($toSeq !== null, fn ($q) => $q->where('sequence', '<=', $toSeq))
            ->orderBy('sequence');
        foreach ($query->cursor() as $row) {
            yield (string) $row->raw_envelope;
        }
    }

    public function listChains(): iterable
    {
        foreach ($this->connection->table('attest_envelopes')->distinct()->pluck('chain_id') as $id) {
            yield (string) $id;
        }
    }

    public function exists(string $chainId): bool
    {
        return $this->connection->table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->exists();
    }
}
