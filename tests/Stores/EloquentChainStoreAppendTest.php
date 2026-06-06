<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores;

use Fissible\Attest\Chain\AppendContext;
use Fissible\Attest\Chain\ContextMismatch;
use Fissible\Attest\Envelope\EvidenceEnvelope;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Events\EnvelopeRecorded;
use Fissible\AttestLaravel\Stores\EloquentChainStore;
use Fissible\AttestLaravel\Stores\Locking\SqliteChainLocker;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class EloquentChainStoreAppendTest extends TestCase
{
    use DatabaseMigrations;

    public function test_append_persists_envelope_and_fires_event_after_commit(): void
    {
        if (getenv('DB_CONNECTION') !== false && getenv('DB_CONNECTION') !== 'sqlite') {
            $this->markTestSkipped('append test runs against sqlite; per-driver tests run in CI matrix');
        }
        Event::fake([EnvelopeRecorded::class]);

        $store = new EloquentChainStore(DB::connection(), new SqliteChainLocker(DB::connection(), 5), Event::getFacadeRoot());
        $kp = KeyPair::generate();
        $signer = new SodiumSigner($kp, keyId: 'k1');

        $signed = $store->append('tenant:5', function (AppendContext $ctx) use ($signer) {
            $env = new EvidenceEnvelope(
                id: '01H00000000000000000000001',
                chain: $ctx->chainId,
                seq: $ctx->sequence,
                ts: $ctx->timestampIso8601,
                type: 'app.event',
                payload: ['k' => 'v'],
                prevHash: $ctx->prevHash,
                keyId: 'k1',
                sigAlg: 'ed25519',
            );
            return SignedEnvelope::sign($env, $signer);
        });

        self::assertSame(1, $signed->envelope->seq);
        self::assertSame(1, DB::table('attest_envelopes')->count());
        Event::assertDispatched(
            EnvelopeRecorded::class,
            fn (EnvelopeRecorded $e) => $e->chainId === 'tenant:5' && $e->signed->envelope->id === $signed->envelope->id,
        );
    }

    public function test_context_mismatch_throws_and_rolls_back(): void
    {
        Event::fake([EnvelopeRecorded::class]);
        $store = new EloquentChainStore(DB::connection(), new SqliteChainLocker(DB::connection(), 5), Event::getFacadeRoot());
        $kp = KeyPair::generate();
        $signer = new SodiumSigner($kp, keyId: 'k1');

        $this->expectException(ContextMismatch::class);
        try {
            $store->append('tenant:5', function (AppendContext $ctx) use ($signer) {
                $env = new EvidenceEnvelope(
                    id: '01H00000000000000000000002',
                    chain: 'WRONG',  // mismatch
                    seq: $ctx->sequence,
                    ts: $ctx->timestampIso8601,
                    type: 'app.event',
                    payload: [],
                    prevHash: $ctx->prevHash,
                    keyId: 'k1',
                    sigAlg: 'ed25519',
                );
                return SignedEnvelope::sign($env, $signer);
            });
        } finally {
            self::assertSame(0, DB::table('attest_envelopes')->count());
            Event::assertNotDispatched(EnvelopeRecorded::class);
        }
    }

    public function test_created_at_round_trips_with_microsecond_precision(): void
    {
        if (getenv('DB_CONNECTION') !== false && getenv('DB_CONNECTION') !== 'sqlite') {
            $this->markTestSkipped('microsecond round-trip test runs per-driver in CI');
        }
        $store = new EloquentChainStore(DB::connection(), new SqliteChainLocker(DB::connection(), 5), \Illuminate\Support\Facades\Event::getFacadeRoot());
        $kp = KeyPair::generate();
        $signer = new SodiumSigner($kp, keyId: 'k1');

        $signed = $store->append('tenant:5', function (AppendContext $ctx) use ($signer) {
            $env = new EvidenceEnvelope(
                id: '01H00000000000000000000003',
                chain: $ctx->chainId,
                seq: $ctx->sequence,
                ts: $ctx->timestampIso8601,
                type: 'app.event',
                payload: [],
                prevHash: $ctx->prevHash,
                keyId: 'k1',
                sigAlg: 'ed25519',
            );
            return SignedEnvelope::sign($env, $signer);
        });

        $row = DB::table('attest_envelopes')->first();
        // Confirm the stored created_at contains a microsecond component
        // (i.e., not truncated to seconds).
        self::assertMatchesRegularExpression('/\.\d{6}\z/', (string) $row->created_at);
    }
}
