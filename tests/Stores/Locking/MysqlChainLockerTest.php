<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Fissible\AttestLaravel\Stores\Locking\MysqlChainLocker;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MysqlChainLockerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DB_CONNECTION') !== 'mysql') {
            $this->markTestSkipped('MySQL locker only runs against mysql');
        }
        Schema::dropIfExists('attest_lock_probe');
        Schema::create('attest_lock_probe', fn ($t) => $t->id());
    }

    public function test_acquires_lock_and_releases_in_finally(): void
    {
        $locker = new MysqlChainLocker(DB::connection(), timeoutSeconds: 5);
        $result = $locker->withChainLock('tenant:5', function () {
            DB::statement('INSERT INTO attest_lock_probe () VALUES ()');
            return 'ok';
        });
        self::assertSame('ok', $result);

        // After return, the lock must be released so a fresh acquisition
        // succeeds immediately.
        $row = DB::selectOne(
            "SELECT IS_FREE_LOCK('attest:chain:" . substr(hash('sha256', 'tenant:5'), 0, 32) . "') AS free"
        );
        self::assertSame(1, (int) $row->free);
    }

    public function test_throws_chainlockunavailable_on_timeout_from_other_session(): void
    {
        $name = 'attest:chain:' . substr(hash('sha256', 'tenant:5'), 0, 32);

        // Acquire on a second connection so the locker's session can't take it.
        $other = DB::connection('mysql');
        $other->reconnect();
        $other->selectOne('SELECT GET_LOCK(?, 5) AS got', [$name]);

        $locker = new MysqlChainLocker(DB::connection(), timeoutSeconds: 1);
        $this->expectException(ChainLockUnavailable::class);
        try {
            $locker->withChainLock('tenant:5', fn () => null);
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    public function test_releases_lock_when_work_throws(): void
    {
        $locker = new MysqlChainLocker(DB::connection(), timeoutSeconds: 5);
        try {
            $locker->withChainLock('tenant:5', function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('expected exception');
        } catch (\RuntimeException) {
        }
        $row = DB::selectOne(
            "SELECT IS_FREE_LOCK('attest:chain:" . substr(hash('sha256', 'tenant:5'), 0, 32) . "') AS free"
        );
        self::assertSame(1, (int) $row->free);
    }
}
