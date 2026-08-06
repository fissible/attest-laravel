<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;
use Illuminate\Database\ConnectionInterface;

/**
 * @internal
 */
final class PostgresChainLocker implements ChainLocker
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $timeoutSeconds,
        private readonly int $pollUs = 50_000,  // 50ms default
    ) {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be >= 0');
        }
        if ($pollUs < 1) {
            throw new \InvalidArgumentException('pollUs must be >= 1');
        }
    }

    public function withChainLock(string $chainId, callable $work): mixed
    {
        $hash = hash('sha256', $chainId, binary: true);
        // Two unsigned 32-bit values from the first 8 bytes …
        $unpacked = unpack('N2', substr($hash, 0, 8));
        if ($unpacked === false) {
            throw new \RuntimeException('Failed to unpack sha256 binary hash');
        }
        // … converted to signed int32 because Postgres int4 is signed.
        $k1 = $this->toSignedInt32($unpacked[1]);
        $k2 = $this->toSignedInt32($unpacked[2]);

        // The transaction must begin *before* polling. pg_try_advisory_xact_lock
        // outside a transaction would acquire and immediately release.
        $this->connection->beginTransaction();
        try {
            $deadline = microtime(true) + $this->timeoutSeconds;
            while (true) {
                $row = $this->connection->selectOne(
                    'SELECT pg_try_advisory_xact_lock(?, ?) AS got',
                    [$k1, $k2],
                );
                if ($row !== null && $this->isTruthy($row->got)) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $this->connection->rollBack();
                    throw new ChainLockUnavailable($chainId);
                }
                usleep($this->pollUs);
            }

            $result = $work();
            $this->connection->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->connection->transactionLevel() > 0) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    /** Accept the PDO boolean-ish forms: true, 1, 't', '1', 'true'.
     *  The exact return type depends on the connection's ATTR_STRINGIFY_FETCHES
     *  and ATTR_EMULATE_PREPARES settings. */
    private function isTruthy(mixed $value): bool
    {
        if ($value === true) {
            return true;
        }
        if ($value === 1) {
            return true;
        }
        if (is_string($value)) {
            $v = strtolower($value);
            return $v === 't' || $v === '1' || $v === 'true';
        }
        return false;
    }

    private function toSignedInt32(int $u): int
    {
        return $u >= 0x80000000 ? $u - 0x100000000 : $u;
    }
}
