<?php
declare(strict_types=1);

/**
 * Ported by value from fissible/attest tests/Anchor/AnchorClaimStoreContractTests.php.
 *
 * TODO(v1.0): once `Fissible\Attest\Testing\*` is extracted as a
 * shipped namespace in core, replace this port with a `require-dev`
 * dependency and `use` the upstream trait. Until then, keep this file
 * in sync with the upstream source by reviewing core's diff against
 * the SHA recorded below.
 *
 * Synced from upstream commit: d12bb61719b79fca0d0a9b53fbdc569a11393ecf
 */

namespace Fissible\AttestLaravel\Tests\Contract;

use Fissible\Attest\Anchor\AnchorClaim;
use Fissible\Attest\Anchor\AnchorClaimStore;

trait AnchorClaimStoreContractTests
{
    abstract protected function store(): AnchorClaimStore;

    protected function claimDetails(?string $claimedAt = null): AnchorClaim
    {
        return new AnchorClaim(
            chainId: 'tenant:5',
            fromSeq: 100,
            toSeq: 149,
            driver: 'opentimestamps',
            claimedBy: 'host:pid:nonce',
            claimedAtIso8601: $claimedAt ?? '2026-05-25T14:30:00.000Z',
        );
    }

    protected function anchorId(string $seed = 'a'): string
    {
        return str_repeat($seed, 64);
    }

    public function test_claim_returns_true_once_then_false_for_duplicate(): void
    {
        $store = $this->store();
        $anchorId = $this->anchorId();

        $this->assertTrue($store->claim($anchorId, $this->claimDetails()));
        $this->assertFalse($store->claim($anchorId, $this->claimDetails()));
    }

    public function test_release_removes_incomplete_claim(): void
    {
        $store = $this->store();
        $anchorId = $this->anchorId();

        $this->assertTrue($store->claim($anchorId, $this->claimDetails()));
        $store->release($anchorId);

        $this->assertTrue($store->claim($anchorId, $this->claimDetails()));
    }

    public function test_complete_is_idempotent_for_same_envelope(): void
    {
        $store = $this->store();
        $anchorId = $this->anchorId();

        $this->assertTrue($store->claim($anchorId, $this->claimDetails()));
        $store->complete($anchorId, '01HX');
        $store->complete($anchorId, '01HX');

        $store->release($anchorId);
        $this->assertFalse($store->claim($anchorId, $this->claimDetails()));
    }

    public function test_complete_rejects_different_envelope_after_completion(): void
    {
        $store = $this->store();
        $anchorId = $this->anchorId();

        $this->assertTrue($store->claim($anchorId, $this->claimDetails()));
        $store->complete($anchorId, '01HX');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('different envelope');

        $store->complete($anchorId, '01HY');
    }

    public function test_reclaim_expired_yields_old_incomplete_claims_keyed_by_anchor_id(): void
    {
        $store = $this->store();
        $oldAnchorId = $this->anchorId('a');
        $newAnchorId = $this->anchorId('b');
        $completedAnchorId = $this->anchorId('c');

        $this->assertTrue($store->claim($oldAnchorId, $this->claimDetails('2000-01-01T00:00:00.000Z')));
        $this->assertTrue($store->claim($newAnchorId, $this->claimDetails(gmdate('Y-m-d\TH:i:s.v\Z'))));
        $this->assertTrue($store->claim($completedAnchorId, $this->claimDetails('2000-01-01T00:00:00.000Z')));
        $store->complete($completedAnchorId, '01HX');

        $expired = iterator_to_array($store->reclaimExpired(3600));

        $this->assertArrayHasKey($oldAnchorId, $expired);
        $this->assertArrayNotHasKey($newAnchorId, $expired);
        $this->assertArrayNotHasKey($completedAnchorId, $expired);
        $this->assertSame('tenant:5', $expired[$oldAnchorId]->chainId);
    }
}
