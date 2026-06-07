<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Services;

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
use Fissible\Attest\Verification\VerificationOutcome;
use Fissible\AttestLaravel\Services\VerifyChain;
use Fissible\AttestLaravel\Support\AnchorDriverResolver;
use Fissible\AttestLaravel\Support\HeaderProviderResolver;
use Fissible\AttestLaravel\Support\TrustedKeyResolver;
use Fissible\AttestLaravel\Support\VerificationExitCode;
use Fissible\AttestLaravel\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParagonIE\ConstantTime\Base64;

final class VerifyChainTest extends TestCase
{
    use RefreshDatabase;

    private KeyPair $keyPair;
    private SodiumSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyPair = KeyPair::generate();
        $this->signer = new SodiumSigner($this->keyPair, 'station-prod');
    }

    public function test_trusted_local_chain_verifies(): void
    {
        $this->buildChain($this->store(), 'trusted', 2);

        $result = $this->service()->verify(
            chainId: 'trusted',
            trustedKeys: [$this->trustedKeyEntry()],
        );

        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertSame(2, $result->chainStats->envelopeCount);
        self::assertSame(2, $result->chainStats->trustedSignatureCount);
    }

    public function test_missing_trusted_keys_returns_untrusted_outcome(): void
    {
        $this->buildChain($this->store(), 'untrusted', 2);

        $result = $this->service()->verify('untrusted');

        self::assertSame(VerificationOutcome::INTEGRITY_VERIFIED_UNTRUSTED, $result->outcome);
        self::assertSame(2, $result->chainStats->untrustedSignatureCount);
    }

    public function test_allow_untrusted_does_not_change_verification_outcome(): void
    {
        $this->buildChain($this->store(), 'allow-untrusted', 1);

        $result = $this->service()->verify('allow-untrusted', allowUntrusted: true);

        self::assertSame(VerificationOutcome::INTEGRITY_VERIFIED_UNTRUSTED, $result->outcome);
        self::assertSame(0, VerificationExitCode::forOutcome($result->outcome, allowUntrusted: true));
    }

    public function test_local_only_anchor_satisfies_local_only_minimum(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'anchored', 2);
        $this->anchorLocalOnly($store, 'anchored', 1, 2);

        $result = $this->service()->verify(
            chainId: 'anchored',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: 'local_only',
            trustedKeys: [$this->trustedKeyEntry()],
        );

        self::assertSame(VerificationOutcome::VERIFIED, $result->outcome);
        self::assertNotNull($result->anchorVerification);
        self::assertSame('local_only', $result->anchorVerification->outcome->value);
    }

    public function test_higher_minimum_fails_local_only_anchor(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'below-min', 2);
        $this->anchorLocalOnly($store, 'below-min', 1, 2);

        $result = $this->service()->verify(
            chainId: 'below-min',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: 'pending',
            trustedKeys: [$this->trustedKeyEntry()],
        );

        self::assertSame(VerificationOutcome::ANCHOR_BELOW_MIN, $result->outcome);
        self::assertNotNull($result->anchorVerification);
        self::assertSame('local_only', $result->anchorVerification->outcome->value);
    }

    public function test_provider_disagreement_uses_fake_header_provider_override(): void
    {
        $store = $this->store();
        $records = $this->buildChain($store, 'headers', 2);
        $target = $this->targetForRecords('headers', $records);
        $this->appendOtsAnchorEnvelope($store, 'headers', $this->otsUpgradedReceipt($target));

        $headers = new HeaderProviderSet(
            VotingHeaderProvider::pass('bitcoin-core', TrustLevel::LOCAL, $target->rootHex),
            VotingHeaderProvider::mismatch('esplora', TrustLevel::REMOTE),
        );

        $result = $this->service($headers)->verify(
            chainId: 'headers',
            fromSeq: 1,
            toSeq: 2,
            minAnchor: 'remote_header_confirmed',
            trustedKeys: [$this->trustedKeyEntry()],
        );

        self::assertSame(VerificationOutcome::PROVIDER_DISAGREEMENT, $result->outcome);
        self::assertNotNull($result->anchorVerification);
        self::assertSame('provider_disagreement', $result->anchorVerification->outcome->value);
        self::assertSame(['bitcoin-core'], $result->anchorVerification->context['passing_providers']);
        self::assertSame(['esplora'], $result->anchorVerification->context['mismatching_providers']);
    }

    public function test_service_provider_binds_verify_service(): void
    {
        $service = $this->app->make(VerifyChain::class);

        self::assertInstanceOf(VerifyChain::class, $service);
    }

    private function store(): ChainStore
    {
        $store = $this->app->make(ChainStore::class);
        self::assertInstanceOf(ChainStore::class, $store);

        return $store;
    }

    private function service(?HeaderProviderSet $headers = null): VerifyChain
    {
        $headerProviderFactory = $headers === null
            ? null
            : static fn (?string $bitcoinCoreRpc, ?string $bitcoinCoreCookie, ?string $esploraUrl): HeaderProviderSet => $headers;

        return new VerifyChain(
            store: $this->store(),
            trustedKeys: new TrustedKeyResolver(new Repository([])),
            headers: new HeaderProviderResolver(new Repository([])),
            anchorDrivers: new AnchorDriverResolver(
                new Repository([]),
                fn (): OpenTimestampsCalendarClient => $this->calendarClient(),
            ),
            config: new Repository([]),
            headerProviderFactory: $headerProviderFactory,
        );
    }

    private function trustedKeyEntry(): string
    {
        return 'station-prod=' . Base64::encode($this->keyPair->publicKey);
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

        (new AnchorService($store, $claimStore, $this->signer, claimedBy: 'verify-chain-test'))
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

    private function calendarClient(): OpenTimestampsCalendarClient
    {
        $factory = new HttpFactory();

        return new OpenTimestampsCalendarClient(
            new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
            $factory,
            $factory,
        );
    }
}

final readonly class VotingHeaderProvider implements BlockHeaderProvider
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
