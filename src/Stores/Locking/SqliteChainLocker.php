<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Illuminate\Database\Connection;

/**
 * @internal
 */
final class SqliteChainLocker implements ChainLocker
{
    public function __construct(
        private readonly Connection $connection,
        private readonly int $timeoutSeconds,
    ) {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be >= 0');
        }
    }

    public function withChainLock(string $chainId, callable $work): mixed
    {
        $pdo = $this->connection->getPdo();

        // SQLite's write lock is *database-wide*, not per-chain.
        // PRAGMA busy_timeout makes contending BEGIN IMMEDIATE wait
        // (with internal retries) up to N ms instead of immediately
        // raising SQLITE_BUSY, so the configured lock_timeout_seconds
        // is honored.
        $pdo->exec('PRAGMA busy_timeout = ' . ($this->timeoutSeconds * 1000));

        // Start BEGIN IMMEDIATE directly and let SQLite be the source of
        // truth. PDO::inTransaction() can be stale on some PHP/PDO SQLite
        // combinations after framework migration or failed-statement work;
        // skipping BEGIN based on that stale state lets callback writes escape
        // the rollback we need to provide.
        $weStartedTransaction = false;
        try {
            // Laravel's $connection->beginTransaction() starts a *deferred*
            // transaction and bumps Laravel's transactionLevel counter.
            // We want BEGIN IMMEDIATE up-front and we don't want Laravel's
            // counter touched; issue everything via raw PDO.
            $pdo->exec('BEGIN IMMEDIATE');
            $weStartedTransaction = true;
        } catch (\PDOException $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'database is locked')) {
                throw new ChainLockUnavailable($chainId);
            }
            if (! str_contains($msg, 'cannot start a transaction within a transaction')) {
                throw $e;
            }
            // Already in a real outer transaction. Run work; the outer
            // caller owns commit/rollback.
        }

        try {
            $result = $work();
            if ($weStartedTransaction) {
                $pdo->exec('COMMIT');
            }
            return $result;
        } catch (\Throwable $e) {
            if ($weStartedTransaction) {
                try {
                    // Some PHP/PDO SQLite versions report false from
                    // inTransaction() after a failed statement even though
                    // the raw BEGIN IMMEDIATE transaction is still open.
                    // Issue raw ROLLBACK so marker writes inside append
                    // callbacks cannot survive a later insert failure.
                    $pdo->exec('ROLLBACK');
                } catch (\PDOException) {
                    // Surface the original exception even if rollback itself fails
                    // (e.g., the transaction was already implicitly closed by the
                    // operation that threw).
                }
            }
            throw $e;
        }
    }
}
