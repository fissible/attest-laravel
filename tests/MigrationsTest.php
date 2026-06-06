<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

final class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_attest_envelopes_table_exists(): void
    {
        self::assertTrue(Schema::hasTable('attest_envelopes'));
        self::assertTrue(Schema::hasColumns('attest_envelopes', [
            'id', 'chain_id', 'sequence', 'envelope_id', 'prev_hash',
            'self_hash', 'key_id', 'type', 'raw_envelope', 'created_at',
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
}
