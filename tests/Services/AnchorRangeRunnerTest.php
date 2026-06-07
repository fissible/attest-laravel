<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Services;

use Fissible\Attest\Anchor\AnchorClaim;
use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Anchor\AnchorEnvelope;
use Fissible\Attest\Anchor\AnchorId;
use Fissible\Attest\Anchor\AnchorTarget;
use Fissible\Attest\Anchor\NullDriver;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsAttestation;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsCalendarClient;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsCodec;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsTimestamp;
use Fissible\Attest\Anchor\OpenTimestampsDriver;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Chain\RawChainStore;
use Fissible\Attest\Merkle\MerkleTree;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Services\AnchorRangeRunner;
use Fissible\AttestLaravel\Services\AnchorRunResult;
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
use Illuminate\Support\Facades\DB;

final class AnchorRangeRunnerTest extends TestCase
{
    use RefreshDatabase;

    private SodiumSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new SodiumSigner(KeyPair::generate(), 'test-key');
    }

    public function test_local_only_anchor_appends_receipt_envelope(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'tenant:5', 3);

        $result = $this->runner()->anchorRange('tenant:5', 1, 3, NullDriver::NAME);

        self::assertSame(AnchorRunResult::ANCHORED, $result->result);
        self::assertSame(NullDriver::NAME, $result->driver);
        self::assertSame('submitted', $result->state);
        self::assertSame('tenant:5', $result->chainId);
        self::assertSame(1, $result->fromSeq);
        self::assertSame(3, $result->toSeq);
        self::assertNotNull($result->anchorId);
        self::assertNotNull($result->envelopeId);
        self::assertSame([], $result->warnings);

        $tail = $store->tail('tenant:5');
        self::assertNotNull($tail);
        self::assertSame(4, $tail->envelope->seq);
        self::assertSame(AnchorEnvelope::SUBMITTED_TYPE, $tail->envelope->type);
        self::assertSame($result->anchorId, $tail->envelope->payload['anchor_id']);
        self::assertSame(4, DB::table('attest_envelopes')->where('chain_id', 'tenant:5')->count());
    }

    public function test_existing_completed_anchor_reconciles_without_appending(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'tenant:5', 2);
        $runner = $this->runner();

        $first = $runner->anchorRange('tenant:5', 1, 2, NullDriver::NAME);
        $second = $runner->anchorRange('tenant:5', 1, 2, NullDriver::NAME);

        self::assertSame(AnchorRunResult::ANCHORED, $first->result);
        self::assertSame(AnchorRunResult::RECONCILED, $second->result);
        self::assertSame($first->anchorId, $second->anchorId);
        self::assertSame($first->envelopeId, $second->envelopeId);
        self::assertSame(3, DB::table('attest_envelopes')->where('chain_id', 'tenant:5')->count());
    }

    public function test_claim_held_without_existing_anchor_yields_skipped(): void
    {
        $store = $this->store();
        $claimStore = $this->claimStore();
        $this->buildChain($store, 'claim-held', 2);
        $anchorId = $this->anchorIdFor($store, 'claim-held', 1, 2, NullDriver::NAME);

        self::assertTrue($claimStore->claim($anchorId, new AnchorClaim(
            chainId: 'claim-held',
            fromSeq: 1,
            toSeq: 2,
            driver: NullDriver::NAME,
            claimedBy: 'other-worker',
            claimedAtIso8601: '2000-01-01T00:00:00.000Z',
        )));

        $result = $this->runner(claimStore: $claimStore)->anchorRange('claim-held', 1, 2, NullDriver::NAME);

        self::assertSame(AnchorRunResult::SKIPPED, $result->result);
        self::assertSame($anchorId, $result->anchorId);
        self::assertNull($result->envelopeId);
        self::assertSame(AnchorRunResult::NO_STATE, $result->state);
        self::assertCount(1, $result->warnings);
        self::assertSame(AnchorRangeRunner::WARNING_CLAIM_HELD, $result->warnings[0]->code);
        self::assertSame(2, DB::table('attest_envelopes')->where('chain_id', 'claim-held')->count());
    }

    public function test_missing_range_throws(): void
    {
        $this->buildChain($this->store(), 'short', 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not read complete anchor range short[1,2]');

        $this->runner()->anchorRange('short', 1, 2, NullDriver::NAME);
    }

    public function test_opentimestamps_anchor_uses_mocked_calendar_client(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'ots', 3);
        $transactions = [];
        $resolver = new AnchorDriverResolver(
            new Repository([]),
            function () use (&$transactions): OpenTimestampsCalendarClient {
                return $this->calendarClient(
                    [new Response(200, ['Content-Type' => OpenTimestampsCalendarClient::CONTENT_TYPE], $this->pendingTimestampBytes())],
                    $transactions,
                );
            },
        );

        $result = $this->runner(resolver: $resolver)->anchorRange(
            chainId: 'ots',
            fromSeq: 1,
            toSeq: 3,
            driverName: OpenTimestampsDriver::NAME,
            calendarUrls: ['https://calendar.example'],
            minCalendars: 1,
        );

        self::assertSame(AnchorRunResult::ANCHORED, $result->result);
        self::assertSame(OpenTimestampsDriver::NAME, $result->driver);
        self::assertSame('pending', $result->state);
        self::assertNotNull($result->anchorId);
        self::assertNotNull($result->envelopeId);
        self::assertCount(1, $transactions);
        self::assertSame('POST', $transactions[0]['request']->getMethod());
        self::assertSame('https://calendar.example/digest', (string) $transactions[0]['request']->getUri());
    }

    public function test_service_provider_binds_runner_when_signer_is_configured(): void
    {
        $previousSeed = getenv('ATTEST_SIGNING_KEY_SEED');
        $previousKeyId = getenv('ATTEST_SIGNING_KEY_ID');
        $seed = str_repeat("\x11", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        putenv('ATTEST_SIGNING_KEY_SEED=' . base64_encode($seed));
        putenv('ATTEST_SIGNING_KEY_ID=test-key');

        try {
            $this->app->forgetInstance(\Fissible\Attest\Signing\Signer::class);

            $runner = $this->app->make(AnchorRangeRunner::class);

            self::assertInstanceOf(AnchorRangeRunner::class, $runner);
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

    private function claimStore(): AnchorClaimStore
    {
        $store = $this->app->make(AnchorClaimStore::class);
        self::assertInstanceOf(AnchorClaimStore::class, $store);

        return $store;
    }

    private function runner(
        ?AnchorClaimStore $claimStore = null,
        ?AnchorDriverResolver $resolver = null,
    ): AnchorRangeRunner {
        return new AnchorRangeRunner(
            $this->store(),
            $claimStore ?? $this->claimStore(),
            $this->signer,
            $resolver ?? new AnchorDriverResolver(new Repository([])),
            claimedBy: 'test-runner',
        );
    }

    private function buildChain(ChainStore $store, string $chainId, int $count): void
    {
        $chain = EvidenceChain::open($store, $chainId, $this->signer);
        for ($i = 1; $i <= $count; $i++) {
            $chain->record('app.event', ['n' => $i]);
        }
    }

    private function anchorIdFor(ChainStore $store, string $chainId, int $fromSeq, int $toSeq, string $driver): string
    {
        self::assertInstanceOf(RawChainStore::class, $store);
        $raw = array_values(iterator_to_array($store->readRawRange($chainId, $fromSeq, $toSeq), false));
        $target = new AnchorTarget(
            chainId: $chainId,
            fromSeq: $fromSeq,
            toSeq: $toSeq,
            merkleAlgorithm: MerkleTree::ALGORITHM,
            rootHex: MerkleTree::rootHex($raw),
        );

        return AnchorId::derive($target, $driver);
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

    private function pendingTimestampBytes(): string
    {
        return OpenTimestampsCodec::encodeTimestampBytes(
            (new OpenTimestampsTimestamp(str_repeat("\x00", 32)))
                ->withAttestation(OpenTimestampsAttestation::pending('https://calendar.example')),
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
