<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Services;

use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Envelope\SignedEnvelope;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Services\IntegrityAudit;
use Fissible\AttestLaravel\Support\Timestamp;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class IntegrityAuditTest extends TestCase
{
    use RefreshDatabase;

    private SodiumSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new SodiumSigner(KeyPair::generate(), 'station-prod');
    }

    public function test_clean_indexes_report_no_drift(): void
    {
        $this->buildChain($this->store(), 'clean', 2);

        $result = $this->service()->audit('clean');

        self::assertSame('clean', $result->chainId);
        self::assertSame(1, $result->fromSeq);
        self::assertNull($result->toSeq);
        self::assertSame(2, $result->checkedCount);
        self::assertSame([], $result->drifts);
    }

    public function test_reports_each_index_column_drift_from_raw_sql_mutations(): void
    {
        foreach ($this->driftCases() as $case) {
            $chainId = 'drift-' . $case['column'];
            $records = $this->buildChain($this->store(), $chainId, 2);

            $expected = $case['mutate']($chainId, $records);
            $result = $this->service()->audit($chainId);

            self::assertSame(2, $result->checkedCount, $case['column']);
            self::assertCount(1, $result->drifts, $case['column']);
            self::assertSame($expected['sequence'], $result->drifts[0]->sequence, $case['column']);
            self::assertSame($case['column'], $result->drifts[0]->column, $case['column']);
            self::assertSame($expected['stored'], $result->drifts[0]->stored, $case['column']);
            self::assertSame($expected['computed'], $result->drifts[0]->computed, $case['column']);
        }
    }

    public function test_reports_provenance_projection_drift(): void
    {
        $records = $this->buildChain(
            $this->store(),
            'provenance',
            2,
            subject: 'order:42',
            correlation: 'invocation-123',
            tenant: 'acme',
        );

        DB::table('attest_envelopes')
            ->where('chain_id', 'provenance')
            ->where('sequence', 2)
            ->update(['subject' => 'order:99', 'tenant' => 'globex']);

        $result = $this->service()->audit('provenance');

        self::assertSame(2, $result->checkedCount);
        self::assertCount(2, $result->drifts);
        self::assertSame(['subject', 'tenant'], array_map(
            static fn ($drift) => $drift->column,
            $result->drifts,
        ));
        self::assertSame('order:99', $result->drifts[0]->stored);
        self::assertSame($records[1]->envelope->subject, $result->drifts[0]->computed);
        self::assertSame('globex', $result->drifts[1]->stored);
        self::assertSame($records[1]->envelope->tenant, $result->drifts[1]->computed);
    }

    /**
     * Blanking a projection column hides the row from correlation queries while
     * the chain still verifies clean — the signed bytes are untouched. The audit
     * is the only thing that catches it, so it must.
     */
    public function test_reports_blanked_correlation_as_drift(): void
    {
        $this->buildChain($this->store(), 'blanked', 1, correlation: 'invocation-123');

        DB::table('attest_envelopes')
            ->where('chain_id', 'blanked')
            ->update(['correlation' => null]);

        $result = $this->service()->audit('blanked');

        self::assertCount(1, $result->drifts);
        self::assertSame('correlation', $result->drifts[0]->column);
        self::assertNull($result->drifts[0]->stored);
        self::assertSame('invocation-123', $result->drifts[0]->computed);
    }

    public function test_range_limits_checked_rows(): void
    {
        $this->buildChain($this->store(), 'range', 3);
        DB::table('attest_envelopes')
            ->where('chain_id', 'range')
            ->where('sequence', 1)
            ->update(['type' => 'mutated.outside.range']);

        $result = $this->service()->audit('range', fromSeq: 2, toSeq: 3);

        self::assertSame(2, $result->fromSeq);
        self::assertSame(3, $result->toSeq);
        self::assertSame(2, $result->checkedCount);
        self::assertSame([], $result->drifts);
    }

    public function test_service_provider_binds_integrity_audit(): void
    {
        $service = $this->app->make(IntegrityAudit::class);

        self::assertInstanceOf(IntegrityAudit::class, $service);
    }

    private function service(): IntegrityAudit
    {
        return $this->app->make(IntegrityAudit::class);
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
    private function buildChain(
        ChainStore $store,
        string $chainId,
        int $count,
        ?string $subject = null,
        ?string $correlation = null,
        ?string $tenant = null,
    ): array {
        $chain = EvidenceChain::open($store, $chainId, $this->signer);
        $records = [];
        for ($i = 1; $i <= $count; $i++) {
            $records[] = $chain->record(
                'app.event',
                ['n' => $i],
                subject: $subject,
                correlation: $correlation,
                tenant: $tenant,
            );
        }

        return $records;
    }

    /**
     * @return list<array{
     *     column: string,
     *     mutate: callable(string, list<SignedEnvelope>): array{sequence:int, stored:mixed, computed:mixed}
     * }>
     */
    private function driftCases(): array
    {
        return [
            [
                'column' => 'sequence',
                'mutate' => static function (string $chainId, array $records): array {
                    DB::table('attest_envelopes')
                        ->where('chain_id', $chainId)
                        ->where('sequence', 2)
                        ->update(['sequence' => 20]);

                    return ['sequence' => 20, 'stored' => 20, 'computed' => $records[1]->envelope->seq];
                },
            ],
            [
                'column' => 'envelope_id',
                'mutate' => static function (string $chainId, array $records): array {
                    $stored = str_repeat('A', 26);
                    DB::table('attest_envelopes')
                        ->where('chain_id', $chainId)
                        ->where('sequence', 2)
                        ->update(['envelope_id' => $stored]);

                    return ['sequence' => 2, 'stored' => $stored, 'computed' => $records[1]->envelope->id];
                },
            ],
            [
                'column' => 'prev_hash',
                'mutate' => static function (string $chainId, array $records): array {
                    unset($records);
                    $stored = str_repeat('f', 64);
                    DB::table('attest_envelopes')
                        ->where('chain_id', $chainId)
                        ->where('sequence', 1)
                        ->update(['prev_hash' => $stored]);

                    return ['sequence' => 1, 'stored' => $stored, 'computed' => null];
                },
            ],
            [
                'column' => 'self_hash',
                'mutate' => static function (string $chainId, array $records): array {
                    $stored = str_repeat('e', 64);
                    DB::table('attest_envelopes')
                        ->where('chain_id', $chainId)
                        ->where('sequence', 2)
                        ->update(['self_hash' => $stored]);

                    return ['sequence' => 2, 'stored' => $stored, 'computed' => $records[1]->selfHash()];
                },
            ],
            [
                'column' => 'key_id',
                'mutate' => static function (string $chainId, array $records): array {
                    $stored = 'wrong-key';
                    DB::table('attest_envelopes')
                        ->where('chain_id', $chainId)
                        ->where('sequence', 2)
                        ->update(['key_id' => $stored]);

                    return ['sequence' => 2, 'stored' => $stored, 'computed' => $records[1]->envelope->keyId];
                },
            ],
            [
                'column' => 'type',
                'mutate' => static function (string $chainId, array $records): array {
                    $stored = 'wrong.type';
                    DB::table('attest_envelopes')
                        ->where('chain_id', $chainId)
                        ->where('sequence', 2)
                        ->update(['type' => $stored]);

                    return ['sequence' => 2, 'stored' => $stored, 'computed' => $records[1]->envelope->type];
                },
            ],
            [
                'column' => 'created_at',
                'mutate' => static function (string $chainId, array $records): array {
                    $stored = '1999-01-01 00:00:00.000000';
                    DB::table('attest_envelopes')
                        ->where('chain_id', $chainId)
                        ->where('sequence', 2)
                        ->update(['created_at' => $stored]);

                    return [
                        'sequence' => 2,
                        'stored' => $stored,
                        'computed' => Timestamp::fromEnvelopeTs($records[1]->envelope->ts),
                    ];
                },
            ],
        ];
    }
}
