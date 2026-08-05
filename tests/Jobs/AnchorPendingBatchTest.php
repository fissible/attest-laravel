<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Jobs;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Anchor\AnchorEnvelope;
use Fissible\Attest\Anchor\NullDriver;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsAttestation;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsCalendarClient;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsCodec;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsTimestamp;
use Fissible\Attest\Anchor\OpenTimestampsDriver;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Jobs\AnchorPendingBatch;
use Fissible\AttestLaravel\Services\AnchorRangeRunner;
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

final class AnchorPendingBatchTest extends TestCase
{
    use RefreshDatabase;

    private SodiumSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new SodiumSigner(KeyPair::generate(), 'app-prod');
    }

    public function test_job_anchors_current_tail_when_to_seq_is_omitted(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'tail', 3);

        $this->job('tail')->handle($this->runner(), $store);

        self::assertSame(4, DB::table('attest_envelopes')->where('chain_id', 'tail')->count());
        $tail = $store->tail('tail');
        self::assertNotNull($tail);
        self::assertSame(AnchorEnvelope::SUBMITTED_TYPE, $tail->envelope->type);
        $receipt = AnchorEnvelope::fromSignedEnvelope($tail);
        self::assertSame(NullDriver::NAME, $receipt->driverName);
        self::assertSame(1, $receipt->target->fromSeq);
        self::assertSame(3, $receipt->target->toSeq);
    }

    public function test_retrying_explicit_range_does_not_duplicate_anchor(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'retry', 2);
        $job = $this->job('retry', toSeq: 2);
        $runner = $this->runner();

        $job->handle($runner, $store);
        $tailAfterFirst = $store->tail('retry');
        self::assertNotNull($tailAfterFirst);

        $job->handle($runner, $store);
        $tailAfterSecond = $store->tail('retry');

        self::assertSame(3, DB::table('attest_envelopes')->where('chain_id', 'retry')->count());
        self::assertSame($tailAfterFirst->envelope->id, $tailAfterSecond?->envelope->id);
    }

    public function test_empty_chain_noops(): void
    {
        $this->job('empty')->handle($this->runner(), $this->store());

        self::assertSame(0, DB::table('attest_envelopes')->where('chain_id', 'empty')->count());
    }

    public function test_job_serializes_without_services(): void
    {
        $job = $this->job(
            chainId: 'serial',
            fromSeq: 2,
            toSeq: 5,
            driver: OpenTimestampsDriver::NAME,
            calendarUrls: ['https://calendar.example'],
            minCalendars: 1,
        );

        $encoded = serialize($job);
        self::assertStringNotContainsString(AnchorRangeRunner::class, $encoded);
        self::assertStringNotContainsString(ChainStore::class, $encoded);

        $decoded = unserialize($encoded, ['allowed_classes' => true]);
        self::assertInstanceOf(AnchorPendingBatch::class, $decoded);
        self::assertSame('serial', $decoded->chainId);
        self::assertSame(2, $decoded->fromSeq);
        self::assertSame(5, $decoded->toSeq);
        self::assertSame(OpenTimestampsDriver::NAME, $decoded->driver);
        self::assertSame(['https://calendar.example'], $decoded->calendarUrls);
        self::assertSame(1, $decoded->minCalendars);
    }

    public function test_opentimestamps_driver_path_uses_injected_resolver(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'ots-job', 2);
        $transactions = [];
        $runner = $this->runner(new AnchorDriverResolver(
            new Repository([]),
            function () use (&$transactions): OpenTimestampsCalendarClient {
                return $this->calendarClient(
                    [new Response(200, ['Content-Type' => OpenTimestampsCalendarClient::CONTENT_TYPE], $this->pendingTimestampBytes())],
                    $transactions,
                );
            },
        ));

        $this->job(
            chainId: 'ots-job',
            toSeq: 2,
            driver: OpenTimestampsDriver::NAME,
            calendarUrls: ['https://calendar.example'],
            minCalendars: 1,
        )->handle($runner, $store);

        $tail = $store->tail('ots-job');
        self::assertNotNull($tail);
        self::assertSame(AnchorEnvelope::SUBMITTED_TYPE, $tail->envelope->type);
        self::assertSame('pending', $tail->envelope->payload['state']);
        self::assertCount(1, $transactions);
        self::assertSame('POST', $transactions[0]['request']->getMethod());
        self::assertSame('https://calendar.example/digest', (string) $transactions[0]['request']->getUri());
    }

    /**
     * @param list<string> $calendarUrls
     */
    private function job(
        string $chainId,
        int $fromSeq = 1,
        ?int $toSeq = null,
        ?string $driver = null,
        array $calendarUrls = [],
        ?int $minCalendars = null,
    ): AnchorPendingBatch {
        return new AnchorPendingBatch(
            chainId: $chainId,
            fromSeq: $fromSeq,
            toSeq: $toSeq,
            driver: $driver,
            calendarUrls: $calendarUrls,
            minCalendars: $minCalendars,
        );
    }

    private function runner(?AnchorDriverResolver $resolver = null): AnchorRangeRunner
    {
        return new AnchorRangeRunner(
            $this->store(),
            $this->claimStore(),
            $this->signer,
            $resolver ?? new AnchorDriverResolver(new Repository([])),
            claimedBy: 'anchor-pending-batch-test',
        );
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

    private function buildChain(ChainStore $store, string $chainId, int $count): void
    {
        $chain = EvidenceChain::open($store, $chainId, $this->signer);
        for ($i = 1; $i <= $count; $i++) {
            $chain->record('app.event', ['n' => $i]);
        }
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
}
