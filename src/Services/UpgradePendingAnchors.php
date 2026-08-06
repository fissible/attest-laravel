<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Services;

use Fissible\Attest\Anchor\AnchorEnvelope;
use Fissible\Attest\Anchor\OpenTimestamps\CalendarUnavailable;
use Fissible\Attest\Anchor\OpenTimestampsDriver;
use Fissible\Attest\Anchor\ProofState;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Signing\Signer;
use Fissible\Attest\Verification\AnchorSetResolver;
use Fissible\Attest\Verification\ResolvedAnchor;
use Fissible\AttestLaravel\Support\AnchorDriverResolver;

/**
 * @internal
 */
final class UpgradePendingAnchors
{
    public function __construct(
        private readonly ChainStore $store,
        private readonly Signer $signer,
        private readonly AnchorDriverResolver $drivers,
    ) {
    }

    /**
     * @param list<string> $calendarUrls
     */
    public function upgradeOne(string $chainId, string $anchorId, array $calendarUrls = []): UpgradeRunResult
    {
        $matchedNonPending = null;
        $candidate = null;

        foreach ($this->otsGroups($chainId) as $resolved) {
            if ($resolved->anchorId !== $anchorId) {
                continue;
            }

            $receipt = $resolved->receipt;
            if ($receipt === null) {
                continue;
            }

            if ($receipt->state !== ProofState::PENDING) {
                $matchedNonPending = $resolved;
                continue;
            }

            $candidate = $resolved;
            break;
        }

        if ($candidate !== null) {
            return $this->upgradeCandidates($chainId, [$candidate], $calendarUrls);
        }

        if ($matchedNonPending !== null) {
            $receipt = $matchedNonPending->receipt;
            assert($receipt !== null);

            return new UpgradeRunResult(unchanged: [
                new UnchangedAnchor(
                    anchorId: $matchedNonPending->anchorId,
                    envelopeId: $this->latestEnvelopeId($matchedNonPending),
                    state: $receipt->state->value,
                ),
            ]);
        }

        return new UpgradeRunResult(failed: [
            new FailedAnchor(
                anchorId: $anchorId,
                envelopeId: null,
                error: 'no pending OTS anchor found for anchor_id: ' . $anchorId,
            ),
        ]);
    }

    /**
     * @param list<string> $calendarUrls
     */
    public function upgradeAllPending(string $chainId, array $calendarUrls = []): UpgradeRunResult
    {
        $candidates = [];

        foreach ($this->otsGroups($chainId) as $resolved) {
            if ($resolved->receipt?->state === ProofState::PENDING) {
                $candidates[] = $resolved;
            }
        }

        if ($candidates === []) {
            return new UpgradeRunResult();
        }

        return $this->upgradeCandidates($chainId, $candidates, $calendarUrls);
    }

    /**
     * @return list<ResolvedAnchor>
     */
    private function otsGroups(string $chainId): array
    {
        $resolved = (new AnchorSetResolver())->resolve($this->store->readRange($chainId, 1));
        $groups = [];

        foreach ($resolved as $anchor) {
            if (! $anchor->valid || $anchor->receipt === null) {
                continue;
            }
            if ($anchor->receipt->driverName !== OpenTimestampsDriver::NAME) {
                continue;
            }

            $groups[] = $anchor;
        }

        return $groups;
    }

    /**
     * @param list<ResolvedAnchor> $candidates
     * @param list<string> $calendarUrls
     */
    private function upgradeCandidates(string $chainId, array $candidates, array $calendarUrls): UpgradeRunResult
    {
        $driver = $this->openTimestampsDriver($calendarUrls);
        $chain = EvidenceChain::open($this->store, $chainId, $this->signer);

        $upgraded = [];
        $unchanged = [];
        $failed = [];

        foreach ($candidates as $resolved) {
            $receipt = $resolved->receipt;
            assert($receipt !== null);
            $previousEnvelopeId = $this->latestEnvelopeId($resolved);

            try {
                $newReceipt = $driver->upgrade($receipt);
            } catch (CalendarUnavailable $e) {
                $failed[] = new FailedAnchor(
                    anchorId: $resolved->anchorId,
                    envelopeId: $previousEnvelopeId,
                    error: 'calendar unavailable: ' . $e->getMessage(),
                );
                continue;
            } catch (\Throwable $e) {
                $failed[] = new FailedAnchor(
                    anchorId: $resolved->anchorId,
                    envelopeId: $previousEnvelopeId,
                    error: $e->getMessage(),
                );
                continue;
            }

            if ($newReceipt->state === $receipt->state) {
                $unchanged[] = new UnchangedAnchor(
                    anchorId: $resolved->anchorId,
                    envelopeId: $previousEnvelopeId,
                    state: $receipt->state->value,
                );
                continue;
            }

            if ($newReceipt->state !== ProofState::UPGRADED) {
                $failed[] = new FailedAnchor(
                    anchorId: $resolved->anchorId,
                    envelopeId: $previousEnvelopeId,
                    error: 'upgraded receipt did not reach upgraded state: ' . $newReceipt->state->value,
                );
                continue;
            }

            $payload = AnchorEnvelope::upgradedPayload(
                $newReceipt,
                supersedesEnvelopeId: $previousEnvelopeId,
            );
            $newEnvelope = $chain->record(AnchorEnvelope::UPGRADED_TYPE, $payload);

            $upgraded[] = new UpgradedAnchor(
                anchorId: $resolved->anchorId,
                previousEnvelopeId: $previousEnvelopeId,
                newEnvelopeId: $newEnvelope->envelope->id,
            );
        }

        return new UpgradeRunResult(
            upgraded: $upgraded,
            unchanged: $unchanged,
            failed: $failed,
        );
    }

    /**
     * @param list<string> $calendarUrls
     */
    private function openTimestampsDriver(array $calendarUrls): OpenTimestampsDriver
    {
        $driver = $this->drivers->resolve(OpenTimestampsDriver::NAME, $calendarUrls);
        if (! $driver instanceof OpenTimestampsDriver) {
            throw new \RuntimeException('AnchorDriverResolver did not return OpenTimestampsDriver for upgrade.');
        }

        return $driver;
    }

    private function latestEnvelopeId(ResolvedAnchor $resolved): ?string
    {
        if ($resolved->envelopeIds === []) {
            return null;
        }

        return $resolved->envelopeIds[count($resolved->envelopeIds) - 1];
    }
}
