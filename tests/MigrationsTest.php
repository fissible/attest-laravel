<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_attest_envelopes_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('attest_envelopes'));
        self::assertTrue(Schema::hasColumns('attest_envelopes', [
            'id', 'chain_id', 'sequence', 'envelope_id', 'prev_hash',
            'self_hash', 'key_id', 'type', 'correlation', 'subject',
            'tenant', 'raw_envelope', 'created_at',
        ]));
    }

    public function test_attest_anchor_claims_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('attest_anchor_claims'));
        self::assertTrue(Schema::hasColumns('attest_anchor_claims', [
            'anchor_id', 'chain_id', 'from_seq', 'to_seq', 'driver',
            'claimed_by', 'claimed_at', 'completed_at', 'completed_envelope_id',
        ]));
    }

    public function test_attest_import_markers_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('attest_import_markers'));
        self::assertTrue(Schema::hasColumns('attest_import_markers', [
            'importer', 'content_hash', 'envelope_id', 'imported_at',
        ]));
    }

    public function test_import_marker_primary_key_rejects_duplicate_content_hash_for_importer(): void
    {
        $hash = str_repeat('a', 64);

        DB::table('attest_import_markers')->insert([
            'importer' => 'ops.updater.audit.global.v1',
            'content_hash' => $hash,
            'envelope_id' => '01J00000000000000000000000',
            'imported_at' => '2026-06-07 00:00:00.000000',
        ]);

        $this->expectException(QueryException::class);

        DB::table('attest_import_markers')->insert([
            'importer' => 'ops.updater.audit.global.v1',
            'content_hash' => $hash,
            'envelope_id' => '01J00000000000000000000001',
            'imported_at' => '2026-06-07 00:00:01.000000',
        ]);
    }

    public function test_import_marker_allows_same_content_hash_for_different_importers(): void
    {
        $hash = str_repeat('b', 64);

        DB::table('attest_import_markers')->insert([
            'importer' => 'ops.updater.audit.global.v1',
            'content_hash' => $hash,
            'envelope_id' => '01J00000000000000000000002',
            'imported_at' => '2026-06-07 00:00:00.000000',
        ]);

        DB::table('attest_import_markers')->insert([
            'importer' => 'other.feed.global.v1',
            'content_hash' => $hash,
            'envelope_id' => '01J00000000000000000000003',
            'imported_at' => '2026-06-07 00:00:01.000000',
        ]);

        self::assertSame(2, DB::table('attest_import_markers')->count());
    }
}
