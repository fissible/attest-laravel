<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores;

use Fissible\Attest\Anchor\AnchorClaim;
use Fissible\AttestLaravel\Stores\EloquentAnchorClaimStore;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class EloquentAnchorClaimStoreTest extends TestCase
{
    use RefreshDatabase;

    private function makeClaim(string $by = 'host:1:abc'): AnchorClaim
    {
        return new AnchorClaim(
            chainId: 'tenant:5',
            fromSeq: 1,
            toSeq: 100,
            driver: 'opentimestamps',
            claimedBy: $by,
            claimedAtIso8601: '2026-06-06T14:32:11.000Z',
        );
    }

    public function test_claim_returns_true_first_time_false_second_time(): void
    {
        $store = new EloquentAnchorClaimStore(DB::connection());
        self::assertTrue($store->claim('aid-1', $this->makeClaim('worker-A')));
        self::assertFalse($store->claim('aid-1', $this->makeClaim('worker-B')));
        self::assertSame(1, DB::table('attest_anchor_claims')->count());
        // The first claimer wins.
        self::assertSame('worker-A', DB::table('attest_anchor_claims')->value('claimed_by'));
    }

    public function test_release_removes_only_incomplete_claims(): void
    {
        $store = new EloquentAnchorClaimStore(DB::connection());
        $store->claim('aid-1', $this->makeClaim());
        $store->release('aid-1');
        self::assertSame(0, DB::table('attest_anchor_claims')->count());

        $store->claim('aid-2', $this->makeClaim());
        $store->complete('aid-2', '01HX00000000000000000000');
        $store->release('aid-2');
        // Completed rows are NOT released.
        self::assertSame(1, DB::table('attest_anchor_claims')->where('anchor_id', 'aid-2')->count());
    }

    public function test_complete_is_atomic_and_idempotent(): void
    {
        $store = new EloquentAnchorClaimStore(DB::connection());
        $store->claim('aid-1', $this->makeClaim());
        $store->complete('aid-1', '01HX00000000000000000000');
        // Idempotent retry with the SAME envelope_id.
        $store->complete('aid-1', '01HX00000000000000000000');
        self::assertSame(1, DB::table('attest_anchor_claims')->count());
    }

    public function test_complete_with_different_envelope_id_throws(): void
    {
        $store = new EloquentAnchorClaimStore(DB::connection());
        $store->claim('aid-1', $this->makeClaim());
        $store->complete('aid-1', '01HX00000000000000000000');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already completed/i');
        $store->complete('aid-1', '01HY00000000000000000000');
    }

    public function test_reclaim_expired_yields_only_expired_incomplete_claims(): void
    {
        $store = new EloquentAnchorClaimStore(DB::connection());

        // Fresh claim — must NOT be yielded.
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $store->claim('aid-fresh', new AnchorClaim(
            chainId: 'c', fromSeq: 1, toSeq: 1, driver: 'd',
            claimedBy: 'fresh', claimedAtIso8601: $now,
        ));

        // Old claim — MUST be yielded.
        $old = (new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $store->claim('aid-old', new AnchorClaim(
            chainId: 'c', fromSeq: 2, toSeq: 2, driver: 'd',
            claimedBy: 'old', claimedAtIso8601: $old,
        ));

        // Completed-and-old — must NOT be yielded (completed_at IS NOT NULL).
        $store->claim('aid-done', new AnchorClaim(
            chainId: 'c', fromSeq: 3, toSeq: 3, driver: 'd',
            claimedBy: 'done', claimedAtIso8601: $old,
        ));
        $store->complete('aid-done', '01HX00000000000000000000');

        $expired = [];
        foreach ($store->reclaimExpired(ttlSeconds: 3600) as $anchorId => $claim) {
            $expired[$anchorId] = $claim;
        }
        self::assertArrayHasKey('aid-old', $expired);
        self::assertCount(1, $expired);
    }
}
