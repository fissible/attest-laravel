<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Stores\Locking;

use Fissible\Attest\Chain\ChainLockUnavailable;

/**
 * @api
 */
interface ChainLocker
{
    /**
     * Acquire a per-chain write lock, invoke $work inside a transaction,
     * and release the lock on commit or rollback.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws ChainLockUnavailable when the lock cannot be acquired within timeout
     */
    public function withChainLock(string $chainId, callable $work): mixed;
}
