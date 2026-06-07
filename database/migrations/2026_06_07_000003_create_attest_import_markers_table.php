<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attest_import_markers', function (Blueprint $t): void {
            $t->string('importer', 191);
            // string (varchar) not char - Postgres CHAR(N) pads trailing
            // spaces, which breaks hash and envelope-id round-trips.
            $t->string('content_hash', 64);
            $t->string('envelope_id', 26);
            $t->timestampTz('imported_at', precision: 6);

            $t->primary(['importer', 'content_hash']);
            $t->index(['importer', 'imported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attest_import_markers');
    }
};
