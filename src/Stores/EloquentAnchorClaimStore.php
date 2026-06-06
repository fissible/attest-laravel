<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores;

use Fissible\Attest\Anchor\AnchorClaim;
use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\AttestLaravel\Support\Timestamp;
use Illuminate\Database\ConnectionInterface;

final class EloquentAnchorClaimStore implements AnchorClaimStore
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function claim(string $anchorId, AnchorClaim $details): bool
    {
        $count = $this->connection->table('attest_anchor_claims')->insertOrIgnore([
            'anchor_id' => $anchorId,
            'chain_id' => $details->chainId,
            'from_seq' => $details->fromSeq,
            'to_seq' => $details->toSeq,
            'driver' => $details->driver,
            'claimed_by' => $details->claimedBy,
            'claimed_at' => Timestamp::fromEnvelopeTs($details->claimedAtIso8601),
        ]);
        return $count > 0;
    }

    public function release(string $anchorId): void
    {
        $this->connection->table('attest_anchor_claims')
            ->where('anchor_id', $anchorId)
            ->whereNull('completed_at')
            ->delete();
    }

    public function complete(string $anchorId, string $envelopeId): void
    {
        // Atomic conditional UPDATE — the WHERE completed_at IS NULL is
        // the compare-and-set. On zero affected rows, re-read to
        // distinguish idempotent retry from real conflict.
        $now = Timestamp::format(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $affected = $this->connection->table('attest_anchor_claims')
            ->where('anchor_id', $anchorId)
            ->whereNull('completed_at')
            ->update([
                'completed_at' => $now,
                'completed_envelope_id' => $envelopeId,
            ]);
        if ($affected > 0) {
            return;
        }

        $existing = $this->connection->table('attest_anchor_claims')
            ->where('anchor_id', $anchorId)
            ->first();
        if ($existing === null) {
            throw new \RuntimeException("AnchorClaim $anchorId not found");
        }
        if ($existing->completed_envelope_id === $envelopeId) {
            return;
        }
        throw new \RuntimeException(sprintf(
            'AnchorClaim %s already completed with a different envelope (existing: %s, requested: %s)',
            $anchorId,
            $existing->completed_envelope_id,
            $envelopeId,
        ));
    }

    public function reclaimExpired(int $ttlSeconds): iterable
    {
        if ($ttlSeconds < 0) {
            throw new \InvalidArgumentException('ttlSeconds must be >= 0');
        }
        $cutoff = Timestamp::format(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->sub(new \DateInterval('PT' . $ttlSeconds . 'S')),
        );
        $rows = $this->connection->table('attest_anchor_claims')
            ->whereNull('completed_at')
            ->where('claimed_at', '<', $cutoff)
            ->cursor();
        foreach ($rows as $row) {
            yield (string) $row->anchor_id => new AnchorClaim(
                chainId: (string) $row->chain_id,
                fromSeq: (int) $row->from_seq,
                toSeq: (int) $row->to_seq,
                driver: (string) $row->driver,
                claimedBy: (string) $row->claimed_by,
                claimedAtIso8601: (string) $row->claimed_at,
            );
        }
    }
}
