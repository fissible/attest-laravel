<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Console;

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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base64;

final class UpgradeCommandTest extends TestCase
{
    use RefreshDatabase;

    private SodiumSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $seed = str_repeat("\x33", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        putenv('ATTEST_SIGNING_KEY_SEED=' . Base64::encode($seed));
        putenv('ATTEST_SIGNING_KEY_ID=upgrade-command-test');
        $this->signer = new SodiumSigner(KeyPair::fromSeed($seed), 'upgrade-command-test');
    }

    protected function tearDown(): void
    {
        putenv('ATTEST_SIGNING_KEY_SEED');
        putenv('ATTEST_SIGNING_KEY_ID');

        parent::tearDown();
    }

    public function test_no_pending_anchors_exits_zero(): void
    {
        $this->buildChain($this->store(), 'none', 2);
        $this->mockCalendars([]);

        $exitCode = Artisan::call('attest:upgrade', [
            '--chain' => 'none',
            '--all-pending' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertSame('attest.cli.upgrade.v1', $payload['format_version']);
        self::assertSame([], $payload['upgraded']);
        self::assertSame([], $payload['unchanged']);
        self::assertSame([], $payload['failed']);
    }

    public function test_missing_required_mode_flags_exits_one(): void
    {
        $this->artisan('attest:upgrade', ['--chain' => 'missing-mode'])
            ->expectsOutputToContain('error: one of --anchor-id or --all-pending is required')
            ->assertExitCode(1);
    }

    public function test_mutually_exclusive_mode_flags_exit_one(): void
    {
        $this->artisan('attest:upgrade', [
            '--chain' => 'bad-mode',
            '--anchor-id' => 'anchor-1',
            '--all-pending' => true,
        ])
            ->expectsOutputToContain('error: --anchor-id and --all-pending are mutually exclusive')
            ->assertExitCode(1);
    }

    public function test_single_pending_ots_anchor_upgrades(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'single', 2);
        $receipt = $this->otsReceipt($store, 'single', 1, 2, ProofState::PENDING);
        $anchorEnvelope = $this->appendOtsAnchorEnvelope($store, 'single', $receipt);
        $transactions = [];
        $this->mockCalendars(
            [new Response(200, ['Content-Type' => OpenTimestampsCalendarClient::CONTENT_TYPE], $this->bitcoinTimestampBytes())],
            $transactions,
        );

        $exitCode = Artisan::call('attest:upgrade', [
            '--chain' => 'single',
            '--anchor-id' => $receipt->anchorId,
            '--calendar-url' => ['https://calendar.example'],
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertCount(1, $payload['upgraded']);
        self::assertSame($receipt->anchorId, $payload['upgraded'][0]['anchor_id']);
        self::assertSame($anchorEnvelope->envelope->id, $payload['upgraded'][0]['previous_envelope_id']);
        self::assertSame([], $payload['unchanged']);
        self::assertSame([], $payload['failed']);
        self::assertCount(1, $transactions);

        $tail = $store->tail('single');
        self::assertNotNull($tail);
        self::assertSame(AnchorEnvelope::UPGRADED_TYPE, $tail->envelope->type);
        self::assertSame($payload['upgraded'][0]['new_envelope_id'], $tail->envelope->id);
    }

    public function test_already_upgraded_anchor_is_idempotent(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'done', 2);
        $receipt = $this->otsReceipt($store, 'done', 1, 2, ProofState::UPGRADED);
        $anchorEnvelope = $this->appendOtsAnchorEnvelope($store, 'done', $receipt);
        $this->mockCalendars([]);

        $exitCode = Artisan::call('attest:upgrade', [
            '--chain' => 'done',
            '--anchor-id' => $receipt->anchorId,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertSame([], $payload['upgraded']);
        self::assertCount(1, $payload['unchanged']);
        self::assertSame($receipt->anchorId, $payload['unchanged'][0]['anchor_id']);
        self::assertSame($anchorEnvelope->envelope->id, $payload['unchanged'][0]['envelope_id']);
        self::assertSame('upgraded', $payload['unchanged'][0]['state']);
        self::assertSame([], $payload['failed']);
        self::assertSame(3, DB::table('attest_envelopes')->where('chain_id', 'done')->count());
    }

    public function test_single_anchor_failure_exits_four(): void
    {
        $this->buildChain($this->store(), 'missing-anchor', 2);
        $this->mockCalendars([]);

        $exitCode = Artisan::call('attest:upgrade', [
            '--chain' => 'missing-anchor',
            '--anchor-id' => 'not-here',
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(4, $exitCode);
        self::assertSame([], $payload['upgraded']);
        self::assertSame([], $payload['unchanged']);
        self::assertCount(1, $payload['failed']);
        self::assertSame('not-here', $payload['failed'][0]['anchor_id']);
    }

    public function test_all_pending_continues_on_one_unavailable_calendar(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'sweep', 4);
        $first = $this->otsReceipt($store, 'sweep', 1, 2, ProofState::PENDING);
        $second = $this->otsReceipt($store, 'sweep', 3, 4, ProofState::PENDING);
        $this->appendOtsAnchorEnvelope($store, 'sweep', $first);
        $this->appendOtsAnchorEnvelope($store, 'sweep', $second);
        $transactions = [];
        $this->mockCalendars(
            [
                new Response(503, [], 'calendar down'),
                new Response(200, ['Content-Type' => OpenTimestampsCalendarClient::CONTENT_TYPE], $this->bitcoinTimestampBytes()),
            ],
            $transactions,
        );

        $exitCode = Artisan::call('attest:upgrade', [
            '--chain' => 'sweep',
            '--all-pending' => true,
            '--calendar-url' => ['https://calendar.example'],
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertCount(1, $payload['upgraded']);
        self::assertCount(1, $payload['unchanged']);
        self::assertSame([], $payload['failed']);
        self::assertSame($first->anchorId, $payload['unchanged'][0]['anchor_id']);
        self::assertSame($second->anchorId, $payload['upgraded'][0]['anchor_id']);
        self::assertCount(2, $transactions);
    }

    private function store(): ChainStore
    {
        $store = $this->app->make(ChainStore::class);
        self::assertInstanceOf(ChainStore::class, $store);

        return $store;
    }

    /**
     * @param list<Response> $responses
     * @param list<array{request: \Psr\Http\Message\RequestInterface, response?: \Psr\Http\Message\ResponseInterface, error?: mixed}> $transactions
     */
    private function mockCalendars(array $responses, array &$transactions = []): void
    {
        $this->app->singleton(
            AnchorDriverResolver::class,
            function () use ($responses, &$transactions): AnchorDriverResolver {
                return new AnchorDriverResolver(
                    new Repository([]),
                    function () use ($responses, &$transactions): OpenTimestampsCalendarClient {
                        return $this->calendarClient($responses, $transactions);
                    },
                );
            },
        );
        $this->app->forgetInstance(UpgradePendingAnchors::class);
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

    /**
     * @return array<string, mixed>
     */
    private function jsonOutput(): array
    {
        $decoded = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
