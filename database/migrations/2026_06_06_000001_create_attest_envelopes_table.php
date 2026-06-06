<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attest_envelopes', function (Blueprint $t): void {
            $t->id();
            $t->string('chain_id', 191);
            $t->unsignedBigInteger('sequence');
            $t->char('envelope_id', 26);
            $t->string('prev_hash', 80)->nullable();
            $t->string('self_hash', 80);
            $t->string('key_id', 191);
            $t->string('type', 191);
            $t->mediumText('raw_envelope');
            // TIMESTAMP(6) on MySQL/SQLite, TIMESTAMPTZ(6) on Postgres.
            $t->timestampTz('created_at', precision: 6);
            $t->unique(['chain_id', 'sequence']);
            $t->unique('envelope_id');
            $t->index(['chain_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attest_envelopes');
    }
};
