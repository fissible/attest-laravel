<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Chain\ChainStore;
use Fissible\AttestLaravel\Facades\Attest;
use Fissible\AttestLaravel\Stores\EloquentChainStore;
use Fissible\AttestLaravel\Stores\Locking\ChainLocker;
use Fissible\AttestLaravel\Support\AttestRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base64;

final class AttestServiceProviderTest extends TestCase
{
    use DatabaseMigrations;  // not RefreshDatabase — locker collides with wrapping txn

    protected function setUp(): void
    {
        parent::setUp();
        // Provide the signer env so Signer bindings resolve.
        putenv('ATTEST_SIGNING_KEY_SEED=' . Base64::encode(random_bytes(32)));
        putenv('ATTEST_SIGNING_KEY_ID=k1');
    }

    protected function tearDown(): void
    {
        putenv('ATTEST_SIGNING_KEY_SEED');
        putenv('ATTEST_SIGNING_KEY_ID');
        parent::tearDown();
    }

    public function test_container_resolves_chain_store(): void
    {
        $store = $this->app->make(ChainStore::class);
        self::assertInstanceOf(EloquentChainStore::class, $store);
    }

    public function test_container_resolves_anchor_claim_store(): void
    {
        $store = $this->app->make(AnchorClaimStore::class);
        self::assertInstanceOf(\Fissible\AttestLaravel\Stores\EloquentAnchorClaimStore::class, $store);
    }

    public function test_facade_returns_fresh_evidence_chain_per_call(): void
    {
        $a = Attest::chain('tenant:5');
        $b = Attest::chain('tenant:5');
        self::assertNotSame($a, $b);   // identity differs
        self::assertSame('tenant:5', $a->chainId);
        self::assertSame('tenant:5', $b->chainId);
    }

    public function test_facade_resolves_attest_registry(): void
    {
        self::assertInstanceOf(AttestRegistry::class, $this->app->make(AttestRegistry::class));
    }

    public function test_records_through_facade_end_to_end(): void
    {
        if (getenv('DB_CONNECTION') !== false && getenv('DB_CONNECTION') !== 'sqlite') {
            $this->markTestSkipped('end-to-end facade test runs against sqlite');
        }
        $signed = Attest::chain('tenant:5')->record('app.event', ['x' => 1]);
        self::assertSame(1, $signed->envelope->seq);
        self::assertSame(1, DB::table('attest_envelopes')->count());
    }

    public function test_resolving_the_chain_store_forces_a_utc_session_time_zone(): void
    {
        $conn = DB::connection();
        $driver = $conn->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            self::markTestSkipped("session time zone is a MySQL-family concern; driver is $driver");
        }

        // Move the session off UTC first. Without this the server's own default may already be
        // UTC, and an assertion of '+00:00' would hold whether or not forceUtc() did anything.
        $conn->statement("SET time_zone = '+07:00'");
        self::assertSame('+07:00', $this->sessionTimeZone($conn), 'precondition: session moved off UTC');

        $this->app->forgetInstance(ChainStore::class);
        $this->app->forgetInstance(ChainLocker::class);
        $this->app->make(ChainStore::class);

        self::assertSame('+00:00', $this->sessionTimeZone($conn));
    }

    private function sessionTimeZone(\Illuminate\Database\Connection $conn): string
    {
        $row = $conn->selectOne('SELECT @@session.time_zone AS tz');
        self::assertNotNull($row);
        return (string) $row->tz;
    }

    public function test_signer_binding_throws_when_env_missing(): void
    {
        putenv('ATTEST_SIGNING_KEY_SEED');
        putenv('ATTEST_SIGNING_KEY_ID');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ATTEST_SIGNING_KEY/i');
        $this->app->make(\Fissible\Attest\Signing\Signer::class);
    }
}
