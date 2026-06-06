<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Tests\Models;

use Fissible\AttestLaravel\Models\AttestEnvelope;
use Fissible\AttestLaravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

final class AttestEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_does_not_set_timestamps(): void
    {
        $m = new AttestEnvelope();
        self::assertFalse($m->timestamps);
    }

    public function test_model_round_trip_via_query_builder(): void
    {
        DB::table('attest_envelopes')->insert([
            'chain_id' => 'c',
            'sequence' => 1,
            'envelope_id' => '01H00000000000000000000000',
            'prev_hash' => null,
            'self_hash' => str_repeat('a', 64),
            'key_id' => 'k1',
            'type' => 't',
            'raw_envelope' => '{"v":1}',
            'created_at' => '2026-06-06 14:32:11.123456',
        ]);

        $m = AttestEnvelope::query()->where('chain_id', 'c')->first();
        self::assertNotNull($m);
        self::assertSame(1, $m->sequence);
        self::assertSame('{"v":1}', $m->raw_envelope);
    }
}
