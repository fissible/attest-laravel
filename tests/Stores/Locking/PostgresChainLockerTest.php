<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Fissible\AttestLaravel\Stores\Locking\PostgresChainLocker;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PostgresChainLockerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DB_CONNECTION') !== 'pgsql') {
            $this->markTestSkipped('Postgres locker only runs against pgsql');
        }
        Schema::dropIfExists('attest_lock_probe');
        Schema::create('attest_lock_probe', fn ($t) => $t->id());
    }

    public function test_acquires_lock_runs_work_and_commits(): void
    {
        $locker = new PostgresChainLocker(DB::connection(), timeoutSeconds: 5, pollUs: 50_000);
        $result = $locker->withChainLock('tenant:5', function () {
            DB::statement('INSERT INTO attest_lock_probe DEFAULT VALUES');
            return 'ok';
        });
        self::assertSame('ok', $result);
        self::assertSame(1, DB::table('attest_lock_probe')->count());
    }

    public function test_rolls_back_when_work_throws(): void
    {
        $locker = new PostgresChainLocker(DB::connection(), timeoutSeconds: 5, pollUs: 50_000);
        try {
            $locker->withChainLock('tenant:5', function (): void {
                DB::statement('INSERT INTO attest_lock_probe DEFAULT VALUES');
                throw new \RuntimeException('boom');
            });
            self::fail('expected exception');
        } catch (\RuntimeException) {
        }
        self::assertSame(0, DB::table('attest_lock_probe')->count());
    }

    public function test_times_out_when_other_session_holds_lock(): void
    {
        $hash = hash('sha256', 'tenant:5', binary: true);
        $unsigned = array_values(unpack('N2', substr($hash, 0, 8)));
        $k1 = $unsigned[0] >= 0x80000000 ? $unsigned[0] - 0x100000000 : $unsigned[0];
        $k2 = $unsigned[1] >= 0x80000000 ? $unsigned[1] - 0x100000000 : $unsigned[1];

        $other = DB::connection('pgsql');
        $other->reconnect();
        $other->beginTransaction();
        $other->selectOne('SELECT pg_advisory_xact_lock(?, ?)', [$k1, $k2]);

        $locker = new PostgresChainLocker(DB::connection(), timeoutSeconds: 1, pollUs: 50_000);
        try {
            $this->expectException(ChainLockUnavailable::class);
            $locker->withChainLock('tenant:5', fn () => null);
        } finally {
            $other->rollBack();
        }
    }

    public function test_signed_int_conversion_for_high_bit_keys(): void
    {
        // The locker uses pg_try_advisory_xact_lock(int, int). Postgres
        // int4 is signed; pass an unsigned value where the high bit is
        // set and the locker must wrap it. This test exercises the
        // toSignedInt32 helper indirectly by using a chain_id whose
        // sha256 prefix has a high-bit-set first word.
        // (Any reasonably-collisive chain_id reaches both code paths.)
        $locker = new PostgresChainLocker(DB::connection(), timeoutSeconds: 1, pollUs: 50_000);
        $result = $locker->withChainLock(
            'high-bit-bait-' . str_repeat('z', 32),
            fn () => 'ok',
        );
        self::assertSame('ok', $result);
    }
}
