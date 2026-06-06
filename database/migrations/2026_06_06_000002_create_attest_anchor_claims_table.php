<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attest_anchor_claims', function (Blueprint $t): void {
            // string (varchar) not char — see envelope migration: Postgres
            // CHAR(N) trailing-space padding breaks identifier round-trip
            // and idempotent-complete comparison.
            $t->string('anchor_id', 64)->primary();
            $t->string('chain_id', 191);
            $t->unsignedBigInteger('from_seq');
            $t->unsignedBigInteger('to_seq');
            $t->string('driver', 64);
            $t->string('claimed_by', 255);
            $t->timestampTz('claimed_at', precision: 6);
            $t->timestampTz('completed_at', precision: 6)->nullable();
            $t->string('completed_envelope_id', 26)->nullable();
            $t->index(['chain_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attest_anchor_claims');
    }
};
