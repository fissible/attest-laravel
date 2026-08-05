<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Migrations;

use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\EvidenceChain;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The projection columns are worthless to an existing installation if only new
 * appends populate them, so the migration backfills from raw_envelope. These
 * tests run down() to reproduce a pre-projection database, then up() again.
 */
final class ProvenanceProjectionBackfillTest extends TestCase
{
    use DatabaseMigrations;

    public function test_backfills_projection_for_rows_written_before_the_columns_existed(): void
    {
        $signed = $this->record('tenant:5', correlation: 'invocation-123', subject: 'order:42', tenant: 'acme');

        $migration = $this->migration();
        $migration->down();

        self::assertFalse(Schema::hasColumn('attest_envelopes', 'correlation'));

        $migration->up();

        $row = DB::table('attest_envelopes')->first();

        self::assertNotNull($row);
        self::assertSame($signed->envelope->correlation, $row->correlation);
        self::assertSame($signed->envelope->subject, $row->subject);
        self::assertSame($signed->envelope->tenant, $row->tenant);
    }

    public function test_backfill_leaves_envelopes_without_provenance_fields_null(): void
    {
        $this->record('tenant:5');

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $row = DB::table('attest_envelopes')->first();

        self::assertNotNull($row);
        self::assertNull($row->correlation);
        self::assertNull($row->subject);
        self::assertNull($row->tenant);
    }

    public function test_backfill_skips_undecodable_rows_without_aborting(): void
    {
        $good = $this->record('tenant:5', correlation: 'invocation-123');

        $migration = $this->migration();
        $migration->down();

        // A row whose raw bytes are already corrupt is a pre-existing integrity
        // problem. The schema change must still complete; attest:integrity:audit
        // is what surfaces the row.
        DB::table('attest_envelopes')->insert([
            'chain_id' => 'tenant:5',
            'sequence' => 99,
            'envelope_id' => str_repeat('Z', 26),
            'prev_hash' => null,
            'self_hash' => str_repeat('a', 64),
            'key_id' => 'k1',
            'type' => 'app.event',
            'raw_envelope' => 'not json at all',
            'created_at' => '2026-06-06 14:32:11.123456',
        ]);

        $migration->up();

        $backfilled = DB::table('attest_envelopes')->where('envelope_id', $good->envelope->id)->first();
        $corrupt = DB::table('attest_envelopes')->where('sequence', 99)->first();

        self::assertNotNull($backfilled);
        self::assertSame('invocation-123', $backfilled->correlation);
        self::assertNotNull($corrupt);
        self::assertNull($corrupt->correlation);
    }

    private function record(
        string $chainId,
        ?string $correlation = null,
        ?string $subject = null,
        ?string $tenant = null,
    ): \Fissible\Attest\Envelope\SignedEnvelope {
        $store = $this->app->make(ChainStore::class);
        self::assertInstanceOf(ChainStore::class, $store);

        $chain = EvidenceChain::open($store, $chainId, new SodiumSigner(KeyPair::generate(), 'k1'));

        return $chain->record(
            'app.event',
            ['k' => 'v'],
            subject: $subject,
            correlation: $correlation,
            tenant: $tenant,
        );
    }

    private function migration(): Migration
    {
        $migration = require dirname(__DIR__, 2)
            . '/database/migrations/2026_08_05_000004_add_provenance_columns_to_attest_envelopes_table.php';

        self::assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
