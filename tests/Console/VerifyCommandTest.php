<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Console;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Anchor\AnchorEnvelope;
use Fissible\Attest\Anchor\AnchorReceipt;
use Fissible\Attest\Anchor\AnchorService;
use Fissible\Attest\Anchor\AnchorTarget;
use Fissible\Attest\Anchor\NullDriver;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsAttestation;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsCalendarClient;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsCodec;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsProof;
use Fissible\Attest\Anchor\OpenTimestamps\OpenTimestampsTimestamp;
use Fissible\Attest\Anchor\OpenTimestampsDriver;
use Fissible\Attest\Anchor\ProofState;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\Attest\Headers\ActiveChainHeader;
use Fissible\Attest\Headers\BlockHeaderProvider;
use Fissible\Attest\Headers\HeaderLookupResult;
use Fissible\Attest\Headers\HeaderProviderSet;
use Fissible\Attest\Headers\TrustLevel;
use Fissible\Attest\Merkle\MerkleTree;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Services\VerifyChain;
use Fissible\AttestLaravel\Support\AnchorDriverResolver;
use Fissible\AttestLaravel\Support\HeaderProviderResolver;
use Fissible\AttestLaravel\Support\TrustedKeyResolver;
use Fissible\AttestLaravel\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base64;

final class VerifyCommandTest extends TestCase
{
    use RefreshDatabase;

    private KeyPair $keyPair;
    private SodiumSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyPair = KeyPair::generate();
        $this->signer = new SodiumSigner($this->keyPair, 'app-prod');
    }

    public function test_verified_chain_exits_zero_and_emits_json(): void
    {
        $this->buildChain($this->store(), 'trusted', 2);

        $exitCode = Artisan::call('attest:verify', [
            '--chain' => 'trusted',
            '--trusted-key' => [$this->trustedKeyEntry()],
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertSame('attest.cli.result.v1', $payload['format_version']);
        self::assertSame('verify', $payload['command']);
        self::assertSame('verified', $payload['outcome']);
        self::assertSame(0, $payload['exit_code']);
        self::assertSame(2, $payload['chain_stats']['envelope_count']);
    }

    public function test_invalid_options_exit_one(): void
    {
        $this->artisan('attest:verify', [
            '--chain' => 'bad-options',
            '--from' => 'two',
        ])
            ->expectsOutputToContain('error: --from must be an integer >= 1')
            ->assertExitCode(1);
    }

    public function test_untrusted_chain_exits_two(): void
    {
        $this->buildChain($this->store(), 'untrusted', 1);

        $exitCode = Artisan::call('attest:verify', [
            '--chain' => 'untrusted',
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(2, $exitCode);
        self::assertSame('integrity_verified_untrusted', $payload['outcome']);
        self::assertSame(2, $payload['exit_code']);
    }

    public function test_allow_untrusted_maps_untrusted_outcome_to_exit_zero(): void
    {
        $this->buildChain($this->store(), 'allow-untrusted', 1);

        $exitCode = Artisan::call('attest:verify', [
            '--chain' => 'allow-untrusted',
            '--allow-untrusted' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertSame('integrity_verified_untrusted', $payload['outcome']);
        self::assertSame(0, $payload['exit_code']);
    }

    public function test_anchor_below_min_exits_three(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'below-min', 2);
        $this->anchorLocalOnly($store, 'below-min', 1, 2);

        $exitCode = Artisan::call('attest:verify', [
            '--chain' => 'below-min',
            '--from' => '1',
            '--to' => '2',
            '--trusted-key' => [$this->trustedKeyEntry()],
            '--min-anchor' => 'pending',
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(3, $exitCode);
        self::assertSame('anchor_below_min', $payload['outcome']);
        self::assertSame(3, $payload['exit_code']);
        self::assertSame('local_only', $payload['anchor_verification']['outcome']);
    }

    public function test_invalid_chain_exits_four(): void
    {
        $this->buildChain($this->store(), 'tampered', 1);
        $this->tamperFirstEnvelopeSignature('tampered');

        $exitCode = Artisan::call('attest:verify', [
            '--chain' => 'tampered',
            '--trusted-key' => [$this->trustedKeyEntry()],
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(4, $exitCode);
        self::assertContains($payload['outcome'], ['invalid_chain', 'invalid_signature']);
        self::assertSame(4, $payload['exit_code']);
    }

    public function test_provider_disagreement_exits_five(): void
    {
        $store = $this->store();
        $records = $this->buildChain($store, 'headers', 2);
        $target = $this->targetForRecords('headers', $records);
        $this->appendOtsAnchorEnvelope($store, 'headers', $this->otsUpgradedReceipt($target));
        $this->bindVerifyServiceWithHeaders(new HeaderProviderSet(
            VerifyCommandHeaderProvider::pass('bitcoin-core', TrustLevel::LOCAL, $target->rootHex),
            VerifyCommandHeaderProvider::mismatch('esplora', TrustLevel::REMOTE),
        ));

        $exitCode = Artisan::call('attest:verify', [
            '--chain' => 'headers',
            '--from' => '1',
            '--to' => '2',
            '--trusted-key' => [$this->trustedKeyEntry()],
            '--min-anchor' => 'remote_header_confirmed',
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(5, $exitCode);
        self::assertSame('provider_disagreement', $payload['outcome']);
        self::assertSame(5, $payload['exit_code']);
        self::assertSame('provider_disagreement', $payload['anchor_verification']['outcome']);
    }

    public function test_human_output_is_concise(): void
    {
        $this->buildChain($this->store(), 'human', 1);

        $this->artisan('attest:verify', [
            '--chain' => 'human',
            '--trusted-key' => [$this->trustedKeyEntry()],
        ])
            ->expectsOutputToContain('chain & signatures: verified (1 envelopes, 1 trusted, 0 untrusted)')
            ->expectsOutputToContain('exit 0')
            ->assertExitCode(0);
    }

    private function store(): ChainStore
    {
        $store = $this->app->make(ChainStore::class);
        self::assertInstanceOf(ChainStore::class, $store);

        return $store;
    }

    private function trustedKeyEntry(): string
    {
        return 'app-prod=' . Base64::encode($this->keyPair->publicKey);
    }

    /**
     * @return list<SignedEnvelope>
     */
    private function buildChain(ChainStore $store, string $chainId, int $count): array
    {
        $chain = EvidenceChain::open($store, $chainId, $this->signer);
        $records = [];
        for ($i = 1; $i <= $count; $i++) {
            $records[] = $chain->record('app.event', ['n' => $i]);
        }

        return $records;
    }

    private function anchorLocalOnly(ChainStore $store, string $chainId, int $fromSeq, int $toSeq): void
    {
        $claimStore = $this->app->make(AnchorClaimStore::class);
        self::assertInstanceOf(AnchorClaimStore::class, $claimStore);

        (new AnchorService($store, $claimStore, $this->signer, claimedBy: 'verify-command-test'))
            ->anchorRange($chainId, $fromSeq, $toSeq, new NullDriver());
    }

    /**
     * @param list<SignedEnvelope> $records
     */
    private function targetForRecords(string $chainId, array $records): AnchorTarget
    {
        return new AnchorTarget(
            chainId: $chainId,
            fromSeq: 1,
            toSeq: count($records),
            merkleAlgorithm: MerkleTree::ALGORITHM,
            rootHex: MerkleTree::rootHex(array_map(
                static fn (SignedEnvelope $signed): string => $signed->signedCanonicalBytes(),
                $records,
            )),
        );
    }

    private function otsUpgradedReceipt(AnchorTarget $target): AnchorReceipt
    {
        $rootBytes = hex2bin($target->rootHex);
        self::assertIsString($rootBytes);

        $timestamp = (new OpenTimestampsTimestamp($rootBytes))
            ->withAttestation(OpenTimestampsAttestation::bitcoin(840000));

        return new AnchorReceipt(
            driverName: OpenTimestampsDriver::NAME,
            target: $target,
            state: ProofState::UPGRADED,
            receiptBytes: OpenTimestampsCodec::encodeDetached(new OpenTimestampsProof($rootBytes, $timestamp)),
            createdAtIso8601: '2026-06-06T00:00:00.000Z',
        );
    }

    private function appendOtsAnchorEnvelope(ChainStore $store, string $chainId, AnchorReceipt $receipt): SignedEnvelope
    {
        return EvidenceChain::open($store, $chainId, $this->signer)
            ->record(AnchorEnvelope::UPGRADED_TYPE, AnchorEnvelope::upgradedPayload($receipt));
    }

    private function tamperFirstEnvelopeSignature(string $chainId): void
    {
        $raw = DB::table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->where('sequence', 1)
            ->value('raw_envelope');
        self::assertIsString($raw);

        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['sig'] = 'base64:' . Base64::encode(str_repeat("\x00", 64));

        DB::table('attest_envelopes')
            ->where('chain_id', $chainId)
            ->where('sequence', 1)
            ->update(['raw_envelope' => json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }

    private function bindVerifyServiceWithHeaders(HeaderProviderSet $headers): void
    {
        $this->app->singleton(
            VerifyChain::class,
            fn (): VerifyChain => new VerifyChain(
                store: $this->store(),
                trustedKeys: new TrustedKeyResolver(new Repository([])),
                headers: new HeaderProviderResolver(new Repository([])),
                anchorDrivers: new AnchorDriverResolver(
                    new Repository([]),
                    fn (): OpenTimestampsCalendarClient => $this->calendarClient(),
                ),
                config: new Repository([]),
                headerProviderFactory: static fn (
                    ?string $bitcoinCoreRpc,
                    ?string $bitcoinCoreCookie,
                    ?string $esploraUrl,
                ): HeaderProviderSet => $headers,
            ),
        );
    }

    private function calendarClient(): OpenTimestampsCalendarClient
    {
        $factory = new HttpFactory();

        return new OpenTimestampsCalendarClient(
            new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
            $factory,
            $factory,
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

final readonly class VerifyCommandHeaderProvider implements BlockHeaderProvider
{
    private function __construct(
        private string $providerName,
        private TrustLevel $trustLevel,
        private string $merkleRoot,
    ) {
    }

    public static function pass(string $name, TrustLevel $trustLevel, string $merkleRoot): self
    {
        return new self($name, $trustLevel, $merkleRoot);
    }

    public static function mismatch(string $name, TrustLevel $trustLevel): self
    {
        return new self($name, $trustLevel, str_repeat('f', 64));
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function trustLevel(): TrustLevel
    {
        return $this->trustLevel;
    }

    public function getActiveChainHeaderByHeight(int $height): HeaderLookupResult
    {
        return HeaderLookupResult::active(
            $this->providerName,
            $this->trustLevel,
            new ActiveChainHeader(
                blockHash: str_repeat('3', 64),
                height: $height,
                confirmations: 7,
                merkleRoot: $this->merkleRoot,
                timeUnixSec: 1713571200,
            ),
        );
    }
}
