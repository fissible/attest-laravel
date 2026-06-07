<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Console;

use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class IntegrityAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private SodiumSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new SodiumSigner(KeyPair::generate(), 'station-prod');
    }

    public function test_clean_chain_exits_zero_and_emits_json(): void
    {
        $this->buildChain($this->store(), 'clean-audit', 2);

        $exitCode = Artisan::call('attest:integrity:audit', [
            '--chain' => 'clean-audit',
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertSame('attest.laravel.integrity-audit.v1', $payload['format_version']);
        self::assertSame('integrity:audit', $payload['command']);
        self::assertSame('clean', $payload['result']);
        self::assertSame(0, $payload['exit_code']);
        self::assertSame('clean-audit', $payload['chain_id']);
        self::assertSame(1, $payload['from_seq']);
        self::assertNull($payload['to_seq']);
        self::assertSame(2, $payload['checked_count']);
        self::assertSame(0, $payload['drift_count']);
        self::assertSame([], $payload['drifts']);
    }

    public function test_mutated_index_column_exits_four_and_reports_drift(): void
    {
        $records = $this->buildChain($this->store(), 'drift-audit', 2);

        DB::table('attest_envelopes')
            ->where('chain_id', 'drift-audit')
            ->where('sequence', 2)
            ->update(['key_id' => 'wrong-key']);

        $exitCode = Artisan::call('attest:integrity:audit', [
            '--chain' => 'drift-audit',
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(4, $exitCode);
        self::assertSame('drift_detected', $payload['result']);
        self::assertSame(4, $payload['exit_code']);
        self::assertSame(2, $payload['checked_count']);
        self::assertSame(1, $payload['drift_count']);
        self::assertSame(2, $payload['drifts'][0]['sequence']);
        self::assertSame('key_id', $payload['drifts'][0]['column']);
        self::assertSame('wrong-key', $payload['drifts'][0]['stored']);
        self::assertSame($records[1]->envelope->keyId, $payload['drifts'][0]['computed']);
    }

    public function test_missing_chain_is_clean_with_zero_checked_rows(): void
    {
        $exitCode = Artisan::call('attest:integrity:audit', [
            '--chain' => 'missing-audit',
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertSame('clean', $payload['result']);
        self::assertSame(0, $payload['checked_count']);
        self::assertSame(0, $payload['drift_count']);
    }

    public function test_invalid_options_exit_one(): void
    {
        $this->artisan('attest:integrity:audit', [
            '--chain' => 'bad-audit',
            '--from' => 'two',
        ])
            ->expectsOutputToContain('error: --from must be an integer >= 1')
            ->assertExitCode(1);
    }

    private function store(): ChainStore
    {
        $store = $this->app->make(ChainStore::class);
        self::assertInstanceOf(ChainStore::class, $store);

        return $store;
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
