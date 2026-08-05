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
use Fissible\Attest\Bundle\BundleConstants;
use Fissible\Attest\Bundle\BundleExportException;
use Fissible\Attest\Bundle\BundleReader;
use Fissible\Attest\Canonical\JcsEncoder;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Chain\RawChainStore;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\Attest\Merkle\MerkleTree;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\Attest\Verification\VerificationOutcome;
use Fissible\Attest\Verification\Warning;
use Fissible\AttestLaravel\Services\BundleOperations;
use Fissible\AttestLaravel\Support\AnchorDriverResolver;
use Fissible\AttestLaravel\Support\HeaderProviderResolver;
use Fissible\AttestLaravel\Support\TrustedKeyResolver;
use Fissible\AttestLaravel\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParagonIE\ConstantTime\Base64;

final class BundleOperationsTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;
    private KeyPair $keyPair;
    private SodiumSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/attest-laravel-bundle-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0o700, recursive: true);
        $this->keyPair = KeyPair::generate();
        $this->signer = new SodiumSigner($this->keyPair, 'app-prod');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function test_export_writes_bundle(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'export', 3);
        $this->anchorLocalOnly($store, 'export', 1, 3);
        $outPath = $this->bundlePath('export.attest');

        $result = $this->service()->export(
            chainId: 'export',
            fromSeq: 1,
            toSeq: 3,
            outPath: $outPath,
            note: 'incident export',
            issuerHint: 'app-prod',
        );

        self::assertFileExists($outPath);
        self::assertGreaterThan(0, $result->bytesWritten);
        self::assertSame($outPath, $result->outPath);
        self::assertSame('export', $result->chainId);
        self::assertSame(3, $result->envelopeCount);
        self::assertSame([], $result->warnings);

        $reader = BundleReader::open($outPath);
        self::assertSame('incident export', $reader->manifest()->note);
        self::assertSame('app-prod', $reader->manifest()->issuerHint);
        self::assertCount(1, $reader->manifest()->chains);
        self::assertCount(1, $reader->manifest()->anchors);
        $reader->close();
    }

    public function test_export_refuses_wider_only_anchor(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'wide', 10);
        $this->anchorLocalOnly($store, 'wide', 1, 10);

        $this->expectException(BundleExportException::class);
        $this->expectExceptionMessageMatches('/exact range/i');

        $this->service()->export('wide', 1, 5, $this->bundlePath('wide.attest'));
    }

    public function test_export_emits_pending_anchor_warning(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'pending', 2);
        $receipt = $this->otsReceipt($store, 'pending', 1, 2, ProofState::PENDING);
        $this->appendOtsAnchorEnvelope($store, 'pending', $receipt);

        $result = $this->service()->export('pending', 1, 2, $this->bundlePath('pending.attest'));

        self::assertCount(1, $result->warnings);
        self::assertSame('bundle_export_pending_anchor', $result->warnings[0]->code);
        self::assertSame($receipt->anchorId, $result->warnings[0]->context['anchor_id']);
    }

    public function test_verify_local_only_anchored_bundle_succeeds(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'verify', 3);
        $this->anchorLocalOnly($store, 'verify', 1, 3);
        $bundlePath = $this->bundlePath('verify.attest');
        $this->service()->export('verify', 1, 3, $bundlePath);

        $result = $this->service()->verify(
            bundlePath: $bundlePath,
            minAnchor: 'local_only',
            trustedKeys: [$this->trustedKeyEntry()],
        );

        self::assertSame('verify', $result->chainId);
        self::assertSame(1, $result->fromSeq);
        self::assertSame(3, $result->toSeq);
        self::assertSame(VerificationOutcome::VERIFIED, $result->verification->outcome);
        self::assertNotNull($result->verification->anchorVerification);
        self::assertSame('local_only', $result->verification->anchorVerification->outcome->value);
    }

    public function test_claimed_keys_alone_yield_untrusted(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'claimed', 2);
        $this->anchorLocalOnly($store, 'claimed', 1, 2);
        $bundlePath = $this->bundlePath('claimed.attest');
        $keyPath = $this->claimedKeyPath('app-prod.pub');

        $this->service()->export('claimed', 1, 2, $bundlePath, claimedKeyFiles: [$keyPath]);

        $reader = BundleReader::open($bundlePath);
        self::assertCount(1, $reader->manifest()->claimedKeys);
        $reader->close();

        $result = $this->service()->verify($bundlePath);

        self::assertSame(VerificationOutcome::INTEGRITY_VERIFIED_UNTRUSTED, $result->verification->outcome);
        self::assertSame(2, $result->verification->chainStats->untrustedSignatureCount);
    }

    public function test_invalid_proof_envelope_signature_drops_anchor_group(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'tampered', 3);
        $this->anchorLocalOnly($store, 'tampered', 1, 3);
        $bundlePath = $this->bundlePath('tampered.attest');
        $this->service()->export('tampered', 1, 3, $bundlePath);
        $this->corruptFirstProofEnvelopeSignature($bundlePath, 'tampered');

        $result = $this->service()->verify(
            bundlePath: $bundlePath,
            minAnchor: 'local_only',
            trustedKeys: [$this->trustedKeyEntry()],
        );

        self::assertSame(VerificationOutcome::ANCHOR_BELOW_MIN, $result->verification->outcome);
        $warningCodes = array_map(
            static fn (Warning $warning): string => $warning->code,
            $result->verification->warnings,
        );
        self::assertContains(Warning::DETACHED_ANCHOR_INVALID_SIGNATURE, $warningCodes);
    }

    public function test_service_provider_binds_bundle_operations(): void
    {
        $service = $this->app->make(BundleOperations::class);

        self::assertInstanceOf(BundleOperations::class, $service);
    }

    private function store(): ChainStore
    {
        $store = $this->app->make(ChainStore::class);
        self::assertInstanceOf(ChainStore::class, $store);

        return $store;
    }

    private function service(): BundleOperations
    {
        return new BundleOperations(
            store: $this->store(),
            trustedKeys: new TrustedKeyResolver(new Repository([])),
            headers: new HeaderProviderResolver(new Repository([])),
            anchorDrivers: new AnchorDriverResolver(
                new Repository([]),
                fn (): OpenTimestampsCalendarClient => $this->calendarClient(),
            ),
            config: new Repository([]),
        );
    }

    private function buildChain(ChainStore $store, string $chainId, int $count): void
    {
        $chain = EvidenceChain::open($store, $chainId, $this->signer);
        for ($i = 1; $i <= $count; $i++) {
            $chain->record('app.event', ['n' => $i]);
        }
    }

    private function anchorLocalOnly(ChainStore $store, string $chainId, int $fromSeq, int $toSeq): void
    {
        $claimStore = $this->app->make(AnchorClaimStore::class);
        self::assertInstanceOf(AnchorClaimStore::class, $claimStore);

        (new AnchorService($store, $claimStore, $this->signer, claimedBy: 'bundle-operations-test'))
            ->anchorRange($chainId, $fromSeq, $toSeq, new NullDriver());
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

    private function corruptFirstProofEnvelopeSignature(string $bundlePath, string $chainId): void
    {
        $entry = BundleConstants::PROOF_ENVELOPES_PREFIX
            . substr(hash('sha256', $chainId), 0, 32) . '.jsonl';

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($bundlePath) === true, 'Bundle ZIP must open for mutation');
        $bytes = $zip->getFromName($entry);
        self::assertIsString($bytes, 'Proof envelope entry must exist');

        $lines = array_values(array_filter(explode("\n", $bytes), static fn (string $line): bool => $line !== ''));
        self::assertNotEmpty($lines, 'Proof envelope entry must contain at least one line');

        $decoded = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['sig'] = 'base64:' . Base64::encode(str_repeat("\x00", SODIUM_CRYPTO_SIGN_BYTES));
        $lines[0] = JcsEncoder::encode($decoded);

        self::assertTrue($zip->deleteName($entry), 'Existing proof envelope entry must be removable');
        self::assertTrue($zip->addFromString($entry, implode("\n", $lines) . "\n"), 'Mutated proof envelope entry must be writable');
        $zip->setCompressionName($entry, \ZipArchive::CM_STORE);
        self::assertTrue($zip->close(), 'Mutated bundle ZIP must close cleanly');
    }

    private function trustedKeyEntry(): string
    {
        return 'app-prod=' . Base64::encode($this->keyPair->publicKey);
    }

    private function claimedKeyPath(string $name): string
    {
        $path = $this->tmpDir . '/' . $name;
        self::assertNotFalse(file_put_contents($path, Base64::encode($this->keyPair->publicKey)));

        return $path;
    }

    private function bundlePath(string $name): string
    {
        return $this->tmpDir . '/' . $name;
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
