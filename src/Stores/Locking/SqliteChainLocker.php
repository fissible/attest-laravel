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

        // Laravel's $connection->beginTransaction() starts a *deferred*
        // transaction and bumps Laravel's transactionLevel counter.
        // We want BEGIN IMMEDIATE up-front and we don't want Laravel's
        // counter touched — issue everything via raw PDO.
        try {
            $pdo->exec('BEGIN IMMEDIATE');
        } catch (\PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'database is locked')) {
                throw new ChainLockUnavailable($chainId);
            }
            throw $e;
        }

        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
