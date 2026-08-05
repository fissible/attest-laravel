<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Console;

use Fissible\Attest\Anchor\AnchorClaimStore;
use Fissible\Attest\Anchor\AnchorEnvelope;
use Fissible\Attest\Anchor\AnchorService;
use Fissible\Attest\Anchor\NullDriver;
use Fissible\Attest\Bundle\BundleConstants;
use Fissible\Attest\Canonical\JcsEncoder;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\Attest\Verification\Warning;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use ParagonIE\ConstantTime\Base64;

final class BundleCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;
    private KeyPair $keyPair;
    private SodiumSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/attest-laravel-bundle-command-' . bin2hex(random_bytes(8));
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

        $exitCode = Artisan::call('attest:bundle:export', [
            '--chain' => 'export',
            '--from' => '1',
            '--to' => '3',
            '--out' => $outPath,
            '--note' => 'incident export',
            '--issuer-hint' => 'app-prod',
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertFileExists($outPath);
        self::assertSame('attest.cli.export.v1', $payload['format_version']);
        self::assertSame('bundle:export', $payload['command']);
        self::assertSame($outPath, $payload['out']);
        self::assertGreaterThan(0, $payload['bytes_written']);
        self::assertSame('export', $payload['chain_segments'][0]['chain_id']);
        self::assertSame(3, $payload['chain_segments'][0]['envelope_count']);
    }

    public function test_export_invalid_options_exits_one(): void
    {
        $this->artisan('attest:bundle:export', [
            '--chain' => 'bad-export',
            '--from' => '1',
            '--to' => '1',
        ])
            ->expectsOutputToContain('error: --out is required')
            ->assertExitCode(1);
    }

    public function test_export_wider_only_anchor_exits_four(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'wide', 10);
        $this->anchorLocalOnly($store, 'wide', 1, 10);

        $this->artisan('attest:bundle:export', [
            '--chain' => 'wide',
            '--from' => '1',
            '--to' => '5',
            '--out' => $this->bundlePath('wide.attest'),
        ])
            ->expectsOutputToContain('wider anchors exist')
            ->assertExitCode(4);
    }

    public function test_verify_local_only_bundle_exits_zero(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'verify', 3);
        $this->anchorLocalOnly($store, 'verify', 1, 3);
        $bundlePath = $this->exportBundle('verify', 1, 3, 'verify.attest');

        $exitCode = Artisan::call('attest:bundle:verify', [
            '--bundle' => $bundlePath,
            '--trusted-key' => [$this->trustedKeyEntry()],
            '--min-anchor' => 'local_only',
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertSame('verified', $payload['outcome']);
        self::assertSame(0, $payload['exit_code']);
        self::assertSame('local_only', $payload['anchor_verification']['outcome']);
    }

    public function test_verify_claimed_key_only_bundle_exits_two(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'claimed', 2);
        $this->anchorLocalOnly($store, 'claimed', 1, 2);
        $keyPath = $this->claimedKeyPath('app-prod.pub');
        $bundlePath = $this->bundlePath('claimed.attest');

        $exitCode = Artisan::call('attest:bundle:export', [
            '--chain' => 'claimed',
            '--from' => '1',
            '--to' => '2',
            '--out' => $bundlePath,
            '--include-claimed-key' => [$keyPath],
        ]);
        self::assertSame(0, $exitCode);

        $exitCode = Artisan::call('attest:bundle:verify', [
            '--bundle' => $bundlePath,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(2, $exitCode);
        self::assertSame('integrity_verified_untrusted', $payload['outcome']);
        self::assertSame(2, $payload['exit_code']);
    }

    public function test_verify_missing_bundle_exits_one(): void
    {
        $missing = $this->bundlePath('missing.attest');

        $this->artisan('attest:bundle:verify', ['--bundle' => $missing])
            ->expectsOutputToContain('error: --bundle path does not exist')
            ->assertExitCode(1);
    }

    public function test_verify_invalid_proof_envelope_signature_exits_three_with_warning_when_min_anchor_required(): void
    {
        $store = $this->store();
        $this->buildChain($store, 'tampered', 3);
        $this->anchorLocalOnly($store, 'tampered', 1, 3);
        $bundlePath = $this->exportBundle('tampered', 1, 3, 'tampered.attest');
        $this->corruptFirstProofEnvelopeSignature($bundlePath, 'tampered');

        $exitCode = Artisan::call('attest:bundle:verify', [
            '--bundle' => $bundlePath,
            '--trusted-key' => [$this->trustedKeyEntry()],
            '--min-anchor' => 'local_only',
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();
        $warningCodes = array_map(
            static fn (array $warning): string => (string) $warning['code'],
            $payload['warnings'],
        );

        self::assertSame(3, $exitCode);
        self::assertSame('anchor_below_min', $payload['outcome']);
        self::assertSame(3, $payload['exit_code']);
        self::assertContains(Warning::DETACHED_ANCHOR_INVALID_SIGNATURE, $warningCodes);
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

    private function anchorLocalOnly(ChainStore $store, string $chainId, int $fromSeq, int $toSeq): void
    {
        $claimStore = $this->app->make(AnchorClaimStore::class);
        self::assertInstanceOf(AnchorClaimStore::class, $claimStore);

        (new AnchorService($store, $claimStore, $this->signer, claimedBy: 'bundle-command-test'))
            ->anchorRange($chainId, $fromSeq, $toSeq, new NullDriver());
    }

    private function exportBundle(string $chainId, int $fromSeq, int $toSeq, string $name): string
    {
        $path = $this->bundlePath($name);
        $exitCode = Artisan::call('attest:bundle:export', [
            '--chain' => $chainId,
            '--from' => (string) $fromSeq,
            '--to' => (string) $toSeq,
            '--out' => $path,
        ]);

        self::assertSame(0, $exitCode);
        self::assertFileExists($path);

        return $path;
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
