<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Fissible\AttestLaravel\Support\ChainIdHasher;
use Illuminate\Database\ConnectionInterface;

/**
 * @internal
 */
final class MysqlChainLocker implements ChainLocker
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $timeoutSeconds,
    ) {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be >= 0');
        }
    }

    public function withChainLock(string $chainId, callable $work): mixed
    {
        $lockName = 'attest:chain:' . ChainIdHasher::hash($chainId);
        $acquired = false;

        try {
            // GET_LOCK returns 1 on success, 0 on timeout, NULL on error.
            // Only the literal 1 counts as acquisition.
            $row = $this->connection->selectOne(
                'SELECT GET_LOCK(?, ?) AS got',
                [$lockName, $this->timeoutSeconds],
            );
            $acquired = $row !== null && (int) $row->got === 1;

            if (! $acquired) {
                throw new ChainLockUnavailable($chainId);
            }

            $this->connection->beginTransaction();
            try {
                $result = $work();
                $this->connection->commit();
                return $result;
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }
        } finally {
            if ($acquired) {
                $this->connection->statement('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }
}
