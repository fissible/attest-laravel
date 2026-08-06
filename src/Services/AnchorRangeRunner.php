<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Anchor\AnchorEnvelope;
use Fissible\Attest\Anchor\AnchorId;
use Fissible\Attest\Anchor\AnchorService;
use Fissible\Attest\Anchor\AnchorTarget;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\RawChainStore;
use Fissible\Attest\Merkle\MerkleTree;
use Fissible\Attest\Signing\Signer;
use Fissible\Attest\Verification\Warning;
use Fissible\AttestLaravel\Support\AnchorDriverResolver;

/**
 * @internal
 */
final class AnchorRangeRunner
{
    public const WARNING_CLAIM_HELD = 'anchor_claim_held';

    public function __construct(
        private readonly ChainStore $store,
        private readonly AnchorClaimStore $claimStore,
        private readonly Signer $signer,
        private readonly AnchorDriverResolver $drivers,
        private readonly ?string $claimedBy = null,
    ) {
    }

    /**
     * @param list<string> $calendarUrls
     */
    public function anchorRange(
        string $chainId,
        int $fromSeq,
        int $toSeq,
        ?string $driverName = null,
        array $calendarUrls = [],
        ?int $minCalendars = null,
    ): AnchorRunResult {
        $driver = $this->drivers->resolve($driverName, $calendarUrls, $minCalendars);
        $preTail = $this->store->tail($chainId);
        $preSeq = $preTail?->envelope->seq ?? 0;

        $service = new AnchorService(
            $this->store,
            $this->claimStore,
            $this->signer,
            claimedBy: $this->claimedBy,
        );

        $envelope = $service->anchorRange($chainId, $fromSeq, $toSeq, $driver);
        $warnings = $service->warnings();

        if ($envelope === null) {
            $anchorId = $this->deriveAnchorId($chainId, $fromSeq, $toSeq, $driver->name());
            $warnings[] = new Warning(
                self::WARNING_CLAIM_HELD,
                'Anchor claim is held by another worker and no existing anchor envelope was found.',
                [
                    'anchor_id' => $anchorId,
                    'chain_id' => $chainId,
                    'from_seq' => $fromSeq,
                    'to_seq' => $toSeq,
                    'driver' => $driver->name(),
                ],
            );

            return new AnchorRunResult(
                result: AnchorRunResult::SKIPPED,
                anchorId: $anchorId,
                envelopeId: null,
                driver: $driver->name(),
                state: AnchorRunResult::NO_STATE,
                chainId: $chainId,
                fromSeq: $fromSeq,
                toSeq: $toSeq,
                warnings: $warnings,
            );
        }

        $receipt = AnchorEnvelope::fromSignedEnvelope($envelope);
        $result = $envelope->envelope->seq > $preSeq
            ? AnchorRunResult::ANCHORED
            : AnchorRunResult::RECONCILED;

        return new AnchorRunResult(
            result: $result,
            anchorId: $receipt->anchorId,
            envelopeId: $envelope->envelope->id,
            driver: $receipt->driverName,
            state: $receipt->state->value,
            chainId: $chainId,
            fromSeq: $fromSeq,
            toSeq: $toSeq,
            warnings: $warnings,
        );
    }

    private function deriveAnchorId(string $chainId, int $fromSeq, int $toSeq, string $driver): string
    {
        $target = new AnchorTarget(
            chainId: $chainId,
            fromSeq: $fromSeq,
            toSeq: $toSeq,
            merkleAlgorithm: MerkleTree::ALGORITHM,
            rootHex: MerkleTree::rootHex($this->canonicalEnvelopeBytes($chainId, $fromSeq, $toSeq)),
        );

        return AnchorId::derive($target, $driver);
    }

    /**
     * @return list<string>
     */
    private function canonicalEnvelopeBytes(string $chainId, int $fromSeq, int $toSeq): array
    {
        $expectedCount = $toSeq - $fromSeq + 1;
        $bytes = $this->store instanceof RawChainStore
            ? array_values(iterator_to_array($this->store->readRawRange($chainId, $fromSeq, $toSeq), false))
            : array_map(
                static fn ($envelope): string => $envelope->signedCanonicalBytes(),
                iterator_to_array($this->store->readRange($chainId, $fromSeq, $toSeq), false),
            );

        if (count($bytes) !== $expectedCount) {
            throw new \RuntimeException(sprintf(
                'Could not read complete anchor range %s[%d,%d]; expected %d envelopes, got %d',
                $chainId,
                $fromSeq,
                $toSeq,
                $expectedCount,
                count($bytes),
            ));
        }

        return $bytes;
    }
}
