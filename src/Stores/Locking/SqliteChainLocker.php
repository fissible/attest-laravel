<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Illuminate\Database\Connection;

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

        // Only start a transaction if we're not already in one. Testbench
        // and other Laravel test traits can leave the PDO in a transaction
        // between tests; SQLite refuses to nest with "cannot start a
        // transaction within a transaction." When we did NOT start the
        // transaction, we don't manage commit/rollback either — the outer
        // caller owns that.
        //
        // PDO::inTransaction() is the primary signal but isn't always
        // reliable on SQLite (some PHP/PDO combos don't track it across
        // certain operations). Fall back to catching the nested-txn error
        // text from BEGIN IMMEDIATE itself.
        $weStartedTransaction = false;
        if (! $pdo->inTransaction()) {
            try {
                // Laravel's $connection->beginTransaction() starts a *deferred*
                // transaction and bumps Laravel's transactionLevel counter.
                // We want BEGIN IMMEDIATE up-front and we don't want Laravel's
                // counter touched — issue everything via raw PDO.
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
                // Already in an outer transaction we couldn't detect via
                // PDO::inTransaction(). Run work; outer caller owns commit/rollback.
            }
        }

        try {
            $result = $work();
            if ($weStartedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($weStartedTransaction && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
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
