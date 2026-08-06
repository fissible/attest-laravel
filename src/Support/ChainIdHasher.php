<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Support;

/**
 * @internal
 */
final class ChainIdHasher
{
    /** Returns the first 32 hex chars of sha256($chainId) — the canonical
     *  locker key for both MySQL named locks and (after derivation) the
     *  two 32-bit keys used by Postgres advisory locks. */
    public static function hash(string $chainId): string
    {
        return substr(hash('sha256', $chainId), 0, 32);
    }
}
