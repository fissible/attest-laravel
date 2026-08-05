<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores;

use Fissible\Attest\Chain\AppendContext;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\ContextMismatch;
use Fissible\Attest\Chain\RawChainStore;
use Fissible\Attest\Envelope\EnvelopeCodec;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\AttestLaravel\Events\EnvelopeRecorded;
use Fissible\AttestLaravel\Stores\Locking\ChainLocker;
use Fissible\AttestLaravel\Support\Timestamp;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

final class EloquentChainStore implements ChainStore, RawChainStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ChainLocker $locker,
        private readonly Dispatcher $events,
    ) {
    }

    public function append(string $chainId, callable $buildAndSign): SignedEnvelope
    {
        $pending = [];

        $signed = $this->locker->withChainLock(
            $chainId,
            function () use ($chainId, $buildAndSign, &$pending) {
                $tail = $this->tail($chainId);
                $context = new AppendContext(
                    chainId: $chainId,
                    sequence: $tail === null ? 1 : $tail->envelope->seq + 1,
                    prevHash: $tail?->selfHash(),
                    timestampIso8601: $this->monotonicTimestamp($tail),
                );

                $signed = $buildAndSign($context);
                $this->validateContext($context, $signed);

                $this->connection->table('attest_envelopes')->insert([
                    'chain_id' => $chainId,
                    'sequence' => $context->sequence,
                    'envelope_id' => $signed->envelope->id,
                    'prev_hash' => $signed->envelope->prevHash,
                    'self_hash' => $signed->selfHash(),
                    'key_id' => $signed->envelope->keyId,
                    'type' => $signed->envelope->type,
                    'correlation' => $signed->envelope->correlation,
                    'subject' => $signed->envelope->subject,
                    'tenant' => $signed->envelope->tenant,
                    'raw_envelope' => $signed->signedCanonicalBytes(),
                    'created_at' => Timestamp::fromEnvelopeTs($signed->envelope->ts),
                ]);

                $pending[] = new EnvelopeRecorded($chainId, $signed);
                return $signed;
            },
        );

        foreach ($pending as $event) {
            $this->events->dispatch($event);
        }
        return $signed;
    }

    private function monotonicTimestamp(?SignedEnvelope $tail): string
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        if ($tail === null) {
            return $now;
        }
        if (strcmp($now, $tail->envelope->ts) > 0) {
            return $now;
        }
        return (new \DateTimeImmutable($tail->envelope->ts))
            ->modify('+1 millisecond')
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    private function validateContext(AppendContext $context, SignedEnvelope $signed): void
    {
        if ($signed->envelope->chain !== $context->chainId
            || $signed->envelope->seq !== $context->sequence
            || $signed->envelope->prevHash !== $context->prevHash
            || $signed->envelope->ts !== $context->timestampIso8601
        ) {
            throw new ContextMismatch(sprintf(
                'Envelope context mismatch (expected chain=%s seq=%d prev=%s ts=%s; got chain=%s seq=%d prev=%s ts=%s)',
                $context->chainId, $context->sequence, $context->prevHash ?? 'null', $context->timestampIso8601,
                $signed->envelope->chain, $signed->envelope->seq, $signed->envelope->prevHash ?? 'null', $signed->envelope->ts,
            ));
        }
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
