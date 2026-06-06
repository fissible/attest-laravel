<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Fissible\AttestLaravel\Stores\Locking\SqliteChainLocker;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SqliteChainLockerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DB_CONNECTION') !== false && getenv('DB_CONNECTION') !== 'sqlite') {
            $this->markTestSkipped('SQLite locker only runs against sqlite');
        }
        Schema::create('attest_lock_probe', fn ($t) => $t->id());
    }

    public function test_acquires_lock_runs_work_and_commits(): void
    {
        $locker = new SqliteChainLocker(DB::connection(), timeoutSeconds: 10);
        $result = $locker->withChainLock('tenant:5', function () {
            DB::statement('INSERT INTO attest_lock_probe DEFAULT VALUES');
            return 'ok';
        });
        self::assertSame('ok', $result);
        self::assertSame(1, DB::table('attest_lock_probe')->count());
    }

    public function test_rolls_back_when_work_throws(): void
    {
        $locker = new SqliteChainLocker(DB::connection(), timeoutSeconds: 10);
        try {
            $locker->withChainLock('tenant:5', function () {
                DB::statement('INSERT INTO attest_lock_probe DEFAULT VALUES');
                throw new \RuntimeException('boom');
            });
            self::fail('expected exception');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame(0, DB::table('attest_lock_probe')->count());
    }

    public function test_sets_pragma_busy_timeout_in_milliseconds(): void
    {
        // The PRAGMA value is set on the connection right before BEGIN
        // IMMEDIATE. We can read it back to confirm it equals
        // timeoutSeconds * 1000.
        $locker = new SqliteChainLocker(DB::connection(), timeoutSeconds: 7);
        $locker->withChainLock('tenant:5', function (): void {
            $row = DB::selectOne('PRAGMA busy_timeout');
            self::assertSame(7000, (int) $row->timeout);
        });
    }
}
