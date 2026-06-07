<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Console;

use Fissible\Attest\Anchor\AnchorEnvelope;
use Fissible\Attest\Anchor\NullDriver;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsCalendarClient;
use Fissible\Attest\Anchor\OpenTimestampsDriver;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Jobs\AnchorPendingBatch;
use Fissible\AttestLaravel\Support\AnchorDriverResolver;
use Fissible\AttestLaravel\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ParagonIE\ConstantTime\Base64;

final class AnchorCommandTest extends TestCase
{
    use RefreshDatabase;

    private SodiumSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $seed = str_repeat("\x22", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        putenv('ATTEST_SIGNING_KEY_SEED=' . Base64::encode($seed));
        putenv('ATTEST_SIGNING_KEY_ID=anchor-command-test');
        $this->signer = new SodiumSigner(KeyPair::fromSeed($seed), 'anchor-command-test');
    }

    protected function tearDown(): void
    {
        putenv('ATTEST_SIGNING_KEY_SEED');
        putenv('ATTEST_SIGNING_KEY_ID');

        parent::tearDown();
    }

    public function test_sync_local_only_anchors_and_emits_json(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'tenant:5', 2);

        $exitCode = Artisan::call('attest:anchor', [
            '--chain' => 'tenant:5',
            '--from' => '1',
            '--to' => '2',
            '--driver' => NullDriver::NAME,
            '--sync' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertSame('attest.cli.anchor.v1', $payload['format_version']);
        self::assertSame('anchored', $payload['result']);
        self::assertSame('tenant:5', $payload['target_chain']);
        self::assertSame(NullDriver::NAME, $payload['driver']);

        self::assertSame(3, DB::table('attest_envelopes')->where('chain_id', 'tenant:5')->count());
        $tail = $store->tail('tenant:5');
        self::assertNotNull($tail);
        self::assertSame(AnchorEnvelope::SUBMITTED_TYPE, $tail->envelope->type);
    }

    public function test_dispatch_mode_pushes_anchor_pending_batch_with_scalar_properties(): void
    {
        Queue::fake();

        $exitCode = Artisan::call('attest:anchor', [
            '--chain' => 'dispatch',
            '--from' => '2',
            '--to' => '5',
            '--driver' => OpenTimestampsDriver::NAME,
            '--calendar-url' => ['https://calendar.example', 'https://calendar2.example'],
            '--min-calendars' => '2',
            '--queue' => 'anchors',
            '--connection' => 'redis',
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertSame('attest.laravel.anchor-dispatch.v1', $payload['format_version']);
        self::assertSame(AnchorPendingBatch::class, $payload['job']);

        Queue::assertPushed(AnchorPendingBatch::class, static function (AnchorPendingBatch $job): bool {
            return $job->chainId === 'dispatch'
                && $job->fromSeq === 2
                && $job->toSeq === 5
                && $job->driver === OpenTimestampsDriver::NAME
                && $job->calendarUrls === ['https://calendar.example', 'https://calendar2.example']
                && $job->minCalendars === 2
                && $job->queue === 'anchors'
                && $job->connection === 'redis';
        });
    }

    public function test_missing_chain_config_exits_one(): void
    {
        config()->set('attest.anchoring.default_chain', null);

        $this->artisan('attest:anchor', ['--sync' => true])
            ->expectsOutputToContain('error: --chain is required')
            ->assertExitCode(1);
    }

    public function test_invalid_driver_exits_one_and_does_not_dispatch(): void
    {
        Queue::fake();

        $this->artisan('attest:anchor', [
            '--chain' => 'dispatch',
            '--driver' => 'bad-driver',
        ])
            ->expectsOutputToContain('error: --driver must be local-only or opentimestamps')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_empty_chain_sync_noops(): void
    {
        $exitCode = Artisan::call('attest:anchor', [
            '--chain' => 'empty',
            '--sync' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertSame('noop', $payload['result']);
        self::assertSame('empty', $payload['target_chain']);

        self::assertSame(0, DB::table('attest_envelopes')->where('chain_id', 'empty')->count());
    }

    public function test_opentimestamps_calendar_unavailable_maps_to_exit_four_in_sync_mode(): void
    {
        $this->buildChain($this->store(), 'ots-fail', 1);
        $this->app->singleton(
            AnchorDriverResolver::class,
            fn (): AnchorDriverResolver => new AnchorDriverResolver(
                new Repository([]),
                fn (): OpenTimestampsCalendarClient => $this->calendarClient([new Response(503)]),
            ),
        );

        $this->artisan('attest:anchor', [
            '--chain' => 'ots-fail',
            '--from' => '1',
            '--to' => '1',
            '--driver' => OpenTimestampsDriver::NAME,
            '--calendar-url' => ['https://calendar.example'],
            '--sync' => true,
        ])
            ->expectsOutputToContain('error: calendar unavailable')
            ->assertExitCode(4);

        self::assertSame(1, DB::table('attest_envelopes')->where('chain_id', 'ots-fail')->count());
    }

    private function store(): ChainStore
    {
        $store = $this->app->make(ChainStore::class);
        self::assertInstanceOf(ChainStore::class, $store);

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
     * @return array<string, mixed>
     */
    private function jsonOutput(): array
    {
        $decoded = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<Response> $responses
     */
    private function calendarClient(array $responses): OpenTimestampsCalendarClient
    {
        $http = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        $factory = new HttpFactory();

        return new OpenTimestampsCalendarClient($http, $factory, $factory);
    }
}
