<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Services;

use Fissible\Attest\Anchor\AnchorEnvelope;
use Fissible\Attest\Anchor\AnchorReceipt;
use Fissible\Attest\Anchor\AnchorTarget;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsAttestation;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsCalendarClient;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsCodec;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsProof;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsTimestamp;
use Fissible\Attest\Anchor\OpenTimestampsDriver;
use Fissible\Attest\Anchor\ProofState;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Chain\RawChainStore;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\Attest\Merkle\MerkleTree;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Services\UpgradePendingAnchors;
use Fissible\AttestLaravel\Support\AnchorDriverResolver;
use Fissible\AttestLaravel\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class UpgradePendingAnchorsTest extends TestCase
{
    use RefreshDatabase;

    private SodiumSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new SodiumSigner(KeyPair::generate(), 'test-key');
    }

    public function test_single_anchor_upgrades_to_new_envelope_with_supersedes_id(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'single', 2);
        $receipt = $this->otsReceipt($store, 'single', 1, 2, ProofState::PENDING);
        $anchorEnvelope = $this->appendOtsAnchorEnvelope($store, 'single', $receipt);
        $transactions = [];

        $result = $this->serviceWithCalendar(
            [new Response(200, ['Content-Type' => OpenTimestampsCalendarClient::CONTENT_TYPE], $this->bitcoinTimestampBytes())],
            $transactions,
        )->upgradeOne('single', $receipt->anchorId, ['https://calendar.example']);

        self::assertCount(1, $result->upgraded);
        self::assertSame([], $result->unchanged);
        self::assertSame([], $result->failed);
        self::assertSame($receipt->anchorId, $result->upgraded[0]->anchorId);
        self::assertSame($anchorEnvelope->envelope->id, $result->upgraded[0]->previousEnvelopeId);
        self::assertNotSame($anchorEnvelope->envelope->id, $result->upgraded[0]->newEnvelopeId);

        $tail = $store->tail('single');
        self::assertNotNull($tail);
        self::assertSame(AnchorEnvelope::UPGRADED_TYPE, $tail->envelope->type);
        self::assertSame($result->upgraded[0]->newEnvelopeId, $tail->envelope->id);
        self::assertSame($anchorEnvelope->envelope->id, $tail->envelope->payload['supersedes_envelope_id']);
        self::assertSame('upgraded', $tail->envelope->payload['state']);
        self::assertCount(1, $transactions);
        self::assertSame('GET', $transactions[0]['request']->getMethod());
    }

    public function test_already_upgraded_anchor_is_idempotent_unchanged(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'done', 2);
        $receipt = $this->otsReceipt($store, 'done', 1, 2, ProofState::UPGRADED);
        $anchorEnvelope = $this->appendOtsAnchorEnvelope($store, 'done', $receipt);

        $result = $this->serviceWithoutCalendarClient()->upgradeOne('done', $receipt->anchorId, ['https://calendar.example']);

        self::assertSame([], $result->upgraded);
        self::assertCount(1, $result->unchanged);
        self::assertSame([], $result->failed);
        self::assertSame($receipt->anchorId, $result->unchanged[0]->anchorId);
        self::assertSame($anchorEnvelope->envelope->id, $result->unchanged[0]->envelopeId);
        self::assertSame('upgraded', $result->unchanged[0]->state);
        self::assertSame($anchorEnvelope->envelope->id, $store->tail('done')?->envelope->id);
    }

    public function test_all_pending_continues_when_one_calendar_is_unavailable(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'sweep', 4);
        $first = $this->otsReceipt($store, 'sweep', 1, 2, ProofState::PENDING);
        $second = $this->otsReceipt($store, 'sweep', 3, 4, ProofState::PENDING);
        $firstEnvelope = $this->appendOtsAnchorEnvelope($store, 'sweep', $first);
        $this->appendOtsAnchorEnvelope($store, 'sweep', $second);
        $transactions = [];

        $result = $this->serviceWithCalendar(
            [
                new Response(503, [], 'calendar down'),
                new Response(200, ['Content-Type' => OpenTimestampsCalendarClient::CONTENT_TYPE], $this->bitcoinTimestampBytes()),
            ],
            $transactions,
        )->upgradeAllPending('sweep', ['https://calendar.example']);

        self::assertCount(1, $result->upgraded);
        self::assertCount(1, $result->unchanged);
        self::assertSame([], $result->failed);
        self::assertSame($first->anchorId, $result->unchanged[0]->anchorId);
        self::assertSame($firstEnvelope->envelope->id, $result->unchanged[0]->envelopeId);
        self::assertSame('pending', $result->unchanged[0]->state);
        self::assertSame($second->anchorId, $result->upgraded[0]->anchorId);
        self::assertCount(2, $transactions);
    }

    public function test_no_pending_anchors_is_success_noop(): void
    {
        $this->buildChain($this->store(), 'none', 2);

        $result = $this->serviceWithoutCalendarClient()->upgradeAllPending('none', ['https://calendar.example']);

        self::assertSame([], $result->upgraded);
        self::assertSame([], $result->unchanged);
        self::assertSame([], $result->failed);
        self::assertSame([], $result->warnings);
    }

    public function test_nonexistent_anchor_id_returns_failure(): void
    {
        $this->buildChain($this->store(), 'missing', 2);

        $result = $this->serviceWithoutCalendarClient()->upgradeOne('missing', 'nonexistent-anchor-id', ['https://calendar.example']);

        self::assertSame([], $result->upgraded);
        self::assertSame([], $result->unchanged);
        self::assertCount(1, $result->failed);
        self::assertSame('nonexistent-anchor-id', $result->failed[0]->anchorId);
        self::assertNull($result->failed[0]->envelopeId);
        self::assertStringContainsString('no pending OTS anchor found', $result->failed[0]->error);
    }

    public function test_service_provider_binds_upgrade_service_when_signer_is_configured(): void
    {
        $previousSeed = getenv('ATTEST_SIGNING_KEY_SEED');
        $previousKeyId = getenv('ATTEST_SIGNING_KEY_ID');
        $seed = str_repeat("\x22", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        putenv('ATTEST_SIGNING_KEY_SEED=' . base64_encode($seed));
        putenv('ATTEST_SIGNING_KEY_ID=test-key');

        try {
            $this->app->forgetInstance(\Fissible\Attest\Signing\Signer::class);

            $service = $this->app->make(UpgradePendingAnchors::class);

            self::assertInstanceOf(UpgradePendingAnchors::class, $service);
        } finally {
            $this->restoreEnv('ATTEST_SIGNING_KEY_SEED', $previousSeed);
            $this->restoreEnv('ATTEST_SIGNING_KEY_ID', $previousKeyId);
        }
    }

    private function store(): ChainStore
    {
        $store = $this->app->make(ChainStore::class);
        self::assertInstanceOf(ChainStore::class, $store);

        return $store;
    }

    private function serviceWithCalendar(array $responses, array &$transactions): UpgradePendingAnchors
    {
        return new UpgradePendingAnchors(
            $this->store(),
            $this->signer,
            new AnchorDriverResolver(
                new Repository([]),
                function () use ($responses, &$transactions): OpenTimestampsCalendarClient {
                    return $this->calendarClient($responses, $transactions);
                },
            ),
        );
    }

    private function serviceWithoutCalendarClient(): UpgradePendingAnchors
    {
        return new UpgradePendingAnchors(
            $this->store(),
            $this->signer,
            new AnchorDriverResolver(
                new Repository([]),
                static fn (): OpenTimestampsCalendarClient => throw new \RuntimeException('calendar client should not be resolved'),
            ),
        );
    }

    private function buildChain(ChainStore $store, string $chainId, int $count): void
    {
        $chain = EvidenceChain::open($store, $chainId, $this->signer);
        for ($i = 1; $i <= $count; $i++) {
            $chain->record('app.event', ['n' => $i]);
        }
    }

    private function targetFor(ChainStore $store, string $chainId, int $fromSeq, int $toSeq): AnchorTarget
    {
        self::assertInstanceOf(RawChainStore::class, $store);
        $rawBytes = iterator_to_array($store->readRawRange($chainId, $fromSeq, $toSeq), false);

        return new AnchorTarget(
            chainId: $chainId,
            fromSeq: $fromSeq,
            toSeq: $toSeq,
            merkleAlgorithm: MerkleTree::ALGORITHM,
            rootHex: MerkleTree::rootHex($rawBytes),
        );
    }

    private function otsReceipt(
        ChainStore $store,
        string $chainId,
        int $fromSeq,
        int $toSeq,
        ProofState $state,
    ): AnchorReceipt {
        $target = $this->targetFor($store, $chainId, $fromSeq, $toSeq);
        $rootBytes = hex2bin($target->rootHex);
        self::assertIsString($rootBytes);

        $timestamp = new OpenTimestampsTimestamp($rootBytes);
        if ($state === ProofState::PENDING) {
            $timestamp = $timestamp->withAttestation(OpenTimestampsAttestation::pending('https://calendar.example'));
        } elseif ($state === ProofState::UPGRADED) {
            $timestamp = $timestamp->withAttestation(OpenTimestampsAttestation::bitcoin(840000));
        } else {
            throw new \InvalidArgumentException('OTS fixture receipts must be pending or upgraded');
        }

        return new AnchorReceipt(
            driverName: OpenTimestampsDriver::NAME,
            target: $target,
            state: $state,
            receiptBytes: OpenTimestampsCodec::encodeDetached(new OpenTimestampsProof($rootBytes, $timestamp)),
            createdAtIso8601: '2026-06-06T00:00:00.000Z',
        );
    }

    private function appendOtsAnchorEnvelope(
        ChainStore $store,
        string $chainId,
        AnchorReceipt $receipt,
    ): SignedEnvelope {
        $chain = EvidenceChain::open($store, $chainId, $this->signer);
        if ($receipt->state === ProofState::UPGRADED) {
            return $chain->record(AnchorEnvelope::UPGRADED_TYPE, AnchorEnvelope::upgradedPayload($receipt));
        }

        return $chain->record(AnchorEnvelope::SUBMITTED_TYPE, AnchorEnvelope::submittedPayload($receipt));
    }

    /**
     * @param list<Response> $responses
     * @param list<array{request: \Psr\Http\Message\RequestInterface, response?: \Psr\Http\Message\ResponseInterface, error?: mixed}> $transactions
     */
    private function calendarClient(array $responses, array &$transactions): OpenTimestampsCalendarClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($transactions));
        $http = new Client(['handler' => $stack]);
        $factory = new HttpFactory();

        return new OpenTimestampsCalendarClient($http, $factory, $factory);
    }

    private function bitcoinTimestampBytes(): string
    {
        return OpenTimestampsCodec::encodeTimestampBytes(
            (new OpenTimestampsTimestamp(str_repeat("\x00", 32)))
                ->withAttestation(OpenTimestampsAttestation::bitcoin(840000)),
        );
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
            return;
        }

        putenv($name . '=' . $value);
    }
}
