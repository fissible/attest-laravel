<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Support;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Signing\Signer;

final class AttestRegistry
{
    public function __construct(
        private readonly ChainStore $store,
        private readonly AnchorClaimStore $claimStore,
        private readonly Signer $signer,
    ) {
    }

    /** Return a fresh EvidenceChain on every call. Caching per chain
     *  in a singleton registry would leak across Octane requests and
     *  grow unbounded under multi-tenant workloads. */
    public function chain(string $chainId): EvidenceChain
    {
        return EvidenceChain::open($this->store, $chainId, $this->signer);
    }

    public function store(): ChainStore
    {
        return $this->store;
    }

    public function claimStore(): AnchorClaimStore
    {
        return $this->claimStore;
    }
}
