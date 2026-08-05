<?php
declare(strict_types=1);

use Fissible\Attest\Envelope\EnvelopeCodec;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attest_envelopes', function (Blueprint $t): void {
            // Read-side projection of the signed correlation/subject/tenant
            // envelope fields. Nullable because all three are optional on
            // EvidenceEnvelope. Never verifier trust input — raw_envelope is
            // the only signed artifact; attest:integrity:audit compares these
            // columns back against it.
            $t->string('correlation', 191)->nullable()->after('type');
            $t->string('subject', 191)->nullable()->after('correlation');
            $t->string('tenant', 191)->nullable()->after('subject');

            // Correlation lookups span chains: a correlation id is assigned by
            // the writing application and is not chain-scoped, so a consumer
            // sharding one chain per tenant still needs a single global answer.
            // Ordered by created_at rather than sequence because sequence is
            // only meaningful within one chain.
            $t->index(['correlation', 'created_at'], 'attest_envelopes_correlation_idx');

            // Tenant-scoped correlation lookup. Correlation ids are unique only
            // by writer convention, so a multi-tenant consumer that cannot rely
            // on that convention scopes the query instead.
            $t->index(['tenant', 'correlation'], 'attest_envelopes_tenant_correlation_idx');

            $t->index(['subject', 'created_at'], 'attest_envelopes_subject_idx');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('attest_envelopes', function (Blueprint $t): void {
            $t->dropIndex('attest_envelopes_correlation_idx');
            $t->dropIndex('attest_envelopes_tenant_correlation_idx');
            $t->dropIndex('attest_envelopes_subject_idx');
            $t->dropColumn(['correlation', 'subject', 'tenant']);
        });
    }

    /**
     * Populate the projection for envelopes written before this migration.
     *
     * Without this, evidence recorded under earlier versions stays invisible to
     * every correlation query — the columns would be null for all existing rows
     * and only new appends would be findable.
     */
    private function backfill(): void
    {
        $connection = Schema::getConnection();

        $connection->table('attest_envelopes')
            ->select(['id', 'raw_envelope'])
            ->orderBy('id')
            ->chunkById(500, function (iterable $rows) use ($connection): void {
                foreach ($rows as $row) {
                    $envelope = $this->decode((string) $row->raw_envelope);

                    if ($envelope === null) {
                        // Undecodable raw bytes are a pre-existing integrity
                        // problem, not something a backfill should mask by
                        // aborting the schema change. Leave the projection null
                        // and let attest:integrity:audit surface the row.
                        continue;
                    }

                    if ($envelope->correlation === null
                        && $envelope->subject === null
                        && $envelope->tenant === null
                    ) {
                        continue;
                    }

                    $connection->table('attest_envelopes')
                        ->where('id', $row->id)
                        ->update([
                            'correlation' => $envelope->correlation,
                            'subject' => $envelope->subject,
                            'tenant' => $envelope->tenant,
                        ]);
                }
            });
    }

    private function decode(string $raw): ?\Fissible\Attest\Envelope\EvidenceEnvelope
    {
        try {
            return EnvelopeCodec::decodeSigned($raw)->envelope;
        } catch (\Throwable) {
            return null;
        }
    }
};
