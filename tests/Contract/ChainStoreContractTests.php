<?php
declare(strict_types=1);

/**
 * Ported by value from fissible/attest tests/Chain/ChainStoreContractTests.php.
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

use Fissible\Attest\Chain\AppendContext;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\ContextMismatch;
use Fissible\Attest\Envelope\EvidenceEnvelope;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Symfony\Component\Uid\Ulid;

/**
 * Shared contract tests for ChainStore implementations.
 * Concrete test classes use this trait and implement makeStore().
 */
trait ChainStoreContractTests
{
    abstract protected function makeStore(): ChainStore;

    private function signer(): SodiumSigner
    {
        return new SodiumSigner(KeyPair::generate(), 'test-key');
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function appendOne(
        ChainStore $store,
        string $chain,
        SodiumSigner $signer,
        string $type = 't',
        array $payload = [],
    ): SignedEnvelope {
        return $store->append($chain, function (AppendContext $ctx) use ($signer, $type, $payload) {
            $env = new EvidenceEnvelope(
                id: (string) Ulid::generate(),
                chain: $ctx->chainId,
                seq: $ctx->sequence,
                ts: $ctx->timestampIso8601,
                type: $type,
                payload: $payload,
                prevHash: $ctx->prevHash,
                keyId: $signer->keyId(),
                sigAlg: 'ed25519',
            );
            return SignedEnvelope::sign($env, $signer);
        });
    }

    public function test_first_append_has_seq_1_and_null_prev_hash(): void
    {
        $store = $this->makeStore();
        $signer = $this->signer();
        $env = $this->appendOne($store, 'c1', $signer);
        $this->assertSame(1, $env->envelope->seq);
        $this->assertNull($env->envelope->prevHash);
    }

    public function test_subsequent_appends_link_to_previous_self_hash(): void
    {
        $store = $this->makeStore();
        $signer = $this->signer();
        $first = $this->appendOne($store, 'c1', $signer);
        $second = $this->appendOne($store, 'c1', $signer);
        $this->assertSame(2, $second->envelope->seq);
        $this->assertSame($first->selfHash(), $second->envelope->prevHash);
    }

    public function test_separate_chains_have_independent_sequences(): void
    {
        $store = $this->makeStore();
        $signer = $this->signer();
        $a = $this->appendOne($store, 'chain-a', $signer);
        $b = $this->appendOne($store, 'chain-b', $signer);
        $this->assertSame(1, $a->envelope->seq);
        $this->assertSame(1, $b->envelope->seq);
    }

    public function test_callback_returning_wrong_seq_throws_context_mismatch(): void
    {
        $store = $this->makeStore();
        $signer = $this->signer();
        $this->expectException(ContextMismatch::class);
        $store->append('c1', function (AppendContext $ctx) use ($signer) {
            $env = new EvidenceEnvelope(
                id: '01H', chain: $ctx->chainId, seq: 999,  // WRONG
                ts: $ctx->timestampIso8601, type: 't', payload: [],
                prevHash: $ctx->prevHash, keyId: $signer->keyId(), sigAlg: 'ed25519',
            );
            return SignedEnvelope::sign($env, $signer);
        });
    }

    public function test_callback_returning_wrong_chain_id_throws_context_mismatch(): void
    {
        $store = $this->makeStore();
        $signer = $this->signer();
        $this->expectException(ContextMismatch::class);
        $store->append('c1', function (AppendContext $ctx) use ($signer) {
            $env = new EvidenceEnvelope(
                id: '01H', chain: 'WRONG', seq: $ctx->sequence,
                ts: $ctx->timestampIso8601, type: 't', payload: [],
                prevHash: $ctx->prevHash, keyId: $signer->keyId(), sigAlg: 'ed25519',
            );
            return SignedEnvelope::sign($env, $signer);
        });
    }

    public function test_tail_returns_null_for_empty_chain(): void
    {
        $store = $this->makeStore();
        $this->assertNull($store->tail('nonexistent'));
    }

    public function test_tail_returns_most_recent_envelope(): void
    {
        $store = $this->makeStore();
        $signer = $this->signer();
        $this->appendOne($store, 'c1', $signer, type: 'first');
        $this->appendOne($store, 'c1', $signer, type: 'second');
        $tail = $store->tail('c1');
        $this->assertNotNull($tail);
        $this->assertSame('second', $tail->envelope->type);
        $this->assertSame(2, $tail->envelope->seq);
    }

    public function test_read_range_returns_envelopes_in_order(): void
    {
        $store = $this->makeStore();
        $signer = $this->signer();
        for ($i = 1; $i <= 5; $i++) {
            $this->appendOne($store, 'c1', $signer, type: "e$i");
        }
        $envs = iterator_to_array($store->readRange('c1', 2, 4), false);
        $this->assertCount(3, $envs);
        $this->assertSame(2, $envs[0]->envelope->seq);
        $this->assertSame(4, $envs[2]->envelope->seq);
    }

    public function test_exists_distinguishes_known_vs_unknown_chains(): void
    {
        $store = $this->makeStore();
        $this->assertFalse($store->exists('c1'));
        $this->appendOne($store, 'c1', $this->signer());
        $this->assertTrue($store->exists('c1'));
    }

    public function test_list_chains_returns_all_chains_with_appends(): void
    {
        $store = $this->makeStore();
        $signer = $this->signer();
        $this->appendOne($store, 'alpha', $signer);
        $this->appendOne($store, 'beta', $signer);
        $list = iterator_to_array($store->listChains(), false);
        sort($list);
        $this->assertSame(['alpha', 'beta'], $list);
    }
}
