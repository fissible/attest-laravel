<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Facades;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\AttestLaravel\Support\AttestRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * @method static EvidenceChain chain(string $chainId)
 * @method static ChainStore store()
 * @method static AnchorClaimStore claimStore()
 */
final class Attest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AttestRegistry::class;
    }
}
