<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Fissible\AttestLaravel\Stores\Locking\SqliteChainLocker;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Database\Connection;
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
        // Drain any stale transaction the previous test (or test-fixture
        // machinery) might have left on the persistent PDO connection.
        // Without this, BEGIN IMMEDIATE inside the locker silently elides
        // into an outer transaction, and our defensive nested-txn branch
        // can't rollback what we didn't open.
        $pdo = DB::connection()->getPdo();
        while ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (\Throwable) {
                break;
            }
        }
        Schema::dropIfExists('attest_lock_probe');
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
        [$connectionName, $dbPath] = $this->freshSqliteConnection();

        try {
            $connection = DB::connection($connectionName);
            self::assertInstanceOf(Connection::class, $connection);
            $connection->statement('CREATE TABLE attest_lock_probe (id INTEGER PRIMARY KEY AUTOINCREMENT)');

            $locker = new SqliteChainLocker($connection, timeoutSeconds: 5);
            try {
                $locker->withChainLock('tenant:5', function () use ($connection): void {
                    $connection->statement('INSERT INTO attest_lock_probe DEFAULT VALUES');
                    throw new \RuntimeException('boom');
                });
                self::fail('expected exception');
            } catch (\RuntimeException) {
            }

            self::assertSame(0, $connection->table('attest_lock_probe')->count());
        } finally {
            DB::purge($connectionName);
            @unlink($dbPath);
        }
    }

    public function test_rolls_back_when_later_statement_fails(): void
    {
        [$connectionName, $dbPath] = $this->freshSqliteConnection();

        try {
            $connection = DB::connection($connectionName);
            self::assertInstanceOf(Connection::class, $connection);
            $connection->statement('CREATE TABLE attest_lock_marker_probe (id TEXT PRIMARY KEY)');
            $connection->statement('CREATE TABLE attest_lock_unique_probe (id TEXT PRIMARY KEY)');
            $connection->table('attest_lock_unique_probe')->insert(['id' => 'duplicate']);

            $locker = new SqliteChainLocker($connection, timeoutSeconds: 5);
            $thrown = null;

            try {
                $locker->withChainLock('tenant:5', function () use ($connection): void {
                    $connection->table('attest_lock_marker_probe')->insert(['id' => 'marker']);
                    $connection->table('attest_lock_unique_probe')->insert(['id' => 'duplicate']);
                });
            } catch (\Throwable $e) {
                $thrown = $e;
            }

            self::assertInstanceOf(\Throwable::class, $thrown);
            self::assertSame(0, $connection->table('attest_lock_marker_probe')->count());
            self::assertSame(1, $connection->table('attest_lock_unique_probe')->count());
        } finally {
            DB::purge($connectionName);
            @unlink($dbPath);
        }
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

    /**
     * @return array{0: string, 1: string}
     */
    private function freshSqliteConnection(): array
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'attest-sqlite-locker-');
        self::assertIsString($dbPath);
        $connectionName = 'sqlite_locker_' . str_replace('.', '_', uniqid('', true));

        config()->set("database.connections.$connectionName", [
            'driver' => 'sqlite',
            'database' => $dbPath,
            'foreign_key_constraints' => true,
        ]);

        return [$connectionName, $dbPath];
    }
}
