<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores;

use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\Signer;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Models\AttestEnvelope;
use Fissible\AttestLaravel\Stores\EloquentChainStore;
use Fissible\AttestLaravel\Stores\Locking\SqliteChainLocker;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class EloquentChainStoreProvenanceProjectionTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('DB_CONNECTION') !== false && getenv('DB_CONNECTION') !== 'sqlite') {
            $this->markTestSkipped('projection tests use SqliteChainLocker; per-driver coverage runs in the CI matrix');
        }
    }

    public function test_append_projects_correlation_subject_and_tenant(): void
    {
        $chain = $this->chain('tenant:5');

        $chain->record(
            type: 'app.event',
            payload: ['k' => 'v'],
            subject: 'order:42',
            correlation: 'invocation-123',
            tenant: 'acme',
        );

        $row = DB::table('attest_envelopes')->first();

        self::assertNotNull($row);
        self::assertSame('invocation-123', $row->correlation);
        self::assertSame('order:42', $row->subject);
        self::assertSame('acme', $row->tenant);
    }

    public function test_append_leaves_projection_null_when_envelope_omits_the_fields(): void
    {
        $this->chain('tenant:5')->record('app.event', ['k' => 'v']);

        $row = DB::table('attest_envelopes')->first();

        self::assertNotNull($row);
        self::assertNull($row->correlation);
        self::assertNull($row->subject);
        self::assertNull($row->tenant);
    }

    public function test_projection_matches_the_signed_envelope(): void
    {
        $signed = $this->chain('tenant:5')->record(
            type: 'app.event',
            payload: ['k' => 'v'],
            correlation: 'invocation-123',
        );

        $model = AttestEnvelope::query()->firstOrFail();

        self::assertSame($signed->envelope->correlation, $model->correlation);
        self::assertSame($signed->envelope->id, $model->signed()->envelope->id);
    }

    public function test_for_correlation_spans_chains_in_time_order(): void
    {
        $signer = $this->signer();

        $first = $this->chain('tenant:5', $signer)->record('app.event', ['n' => 1], correlation: 'invocation-123');
        $second = $this->chain('tenant:9', $signer)->record('app.event', ['n' => 2], correlation: 'invocation-123');
        $this->chain('tenant:5', $signer)->record('app.event', ['n' => 3], correlation: 'other-invocation');

        $found = AttestEnvelope::query()->forCorrelation('invocation-123')->get();

        self::assertCount(2, $found);
        self::assertSame(
            [$first->envelope->id, $second->envelope->id],
            $found->pluck('envelope_id')->all(),
        );
        self::assertSame(['tenant:5', 'tenant:9'], $found->pluck('chain_id')->all());
    }

    public function test_for_correlation_returns_nothing_for_an_unknown_id(): void
    {
        $this->chain('tenant:5')->record('app.event', ['k' => 'v'], correlation: 'invocation-123');

        self::assertCount(0, AttestEnvelope::query()->forCorrelation('nope')->get());
    }

    public function test_for_tenant_scopes_a_correlation_shared_across_tenants(): void
    {
        $signer = $this->signer();

        $this->chain('tenant:5', $signer)->record('app.event', ['n' => 1], correlation: 'shared', tenant: 'acme');
        $this->chain('tenant:9', $signer)->record('app.event', ['n' => 2], correlation: 'shared', tenant: 'globex');

        $found = AttestEnvelope::query()->forTenant('acme')->forCorrelation('shared')->get();

        self::assertCount(1, $found);
        self::assertSame('acme', $found->first()?->tenant);
    }

    public function test_for_subject_orders_oldest_first(): void
    {
        $signer = $this->signer();

        $first = $this->chain('tenant:5', $signer)->record('app.event', ['n' => 1], subject: 'order:42');
        $second = $this->chain('tenant:5', $signer)->record('app.event', ['n' => 2], subject: 'order:42');

        $found = AttestEnvelope::query()->forSubject('order:42')->get();

        self::assertSame(
            [$first->envelope->id, $second->envelope->id],
            $found->pluck('envelope_id')->all(),
        );
    }

    private function chain(string $chainId, ?Signer $signer = null): EvidenceChain
    {
        Event::fake();

        $store = new EloquentChainStore(
            DB::connection(),
            new SqliteChainLocker(DB::connection(), 5),
            Event::getFacadeRoot(),
        );

        return EvidenceChain::open($store, $chainId, $signer ?? $this->signer());
    }

    private function signer(): Signer
    {
        static $signer = null;

        return $signer ??= new SodiumSigner(KeyPair::generate(), keyId: 'k1');
    }
}
