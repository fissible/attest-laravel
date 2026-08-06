<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Models;

use Fissible\Attest\Envelope\EnvelopeCodec;
use Fissible\Attest\Envelope\SignedEnvelope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-side query convenience over attest_envelopes. The store owns
 * all writes; do NOT call ->save() to add envelopes.
 *
 * The correlation/subject/tenant columns are a projection of the signed
 * envelope, never verifier trust input: they make lookups possible without
 * decoding every row, and attest:integrity:audit compares them back against
 * raw_envelope. Call signed() when you need the artifact itself.
 *
 * @property int $id
 * @property string $chain_id
 * @property int $sequence
 * @property string $envelope_id
 * @property ?string $prev_hash
 * @property string $self_hash
 * @property string $key_id
 * @property string $type
 * @property ?string $correlation
 * @property ?string $subject
 * @property ?string $tenant
 * @property string $raw_envelope
 * @property \Illuminate\Support\Carbon $created_at
 * @api
 */
final class AttestEnvelope extends Model
{
    protected $table = 'attest_envelopes';

    /** Store writes created_at explicitly from the envelope ts; no
     *  updated_at exists. */
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'sequence' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Envelopes sharing a correlation id, oldest first.
     *
     * Deliberately not chain-scoped: a correlation id is assigned by the
     * writing application, so a consumer sharding one chain per tenant still
     * expects a single answer across chains. Ordered by created_at because
     * sequence only orders within one chain, with envelope_id — a ULID, and so
     * lexicographically time-sortable — breaking ties.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCorrelation(Builder $query, string $correlation): Builder
    {
        // orderBy() is forwarded to the query builder, so chain into $query
        // rather than returning the forwarded result.
        $query->where('correlation', $correlation)
            ->orderBy('created_at')
            ->orderBy('envelope_id');

        return $query;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSubject(Builder $query, string $subject): Builder
    {
        $query->where('subject', $subject)
            ->orderBy('created_at')
            ->orderBy('envelope_id');

        return $query;
    }

    /**
     * Scope to one tenant. Correlation ids are unique only by writer
     * convention, so a multi-tenant consumer that cannot rely on that
     * convention combines this with forCorrelation().
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTenant(Builder $query, string $tenant): Builder
    {
        return $query->where('tenant', $tenant);
    }

    /** Decode the signed artifact this row projects. */
    public function signed(): SignedEnvelope
    {
        return EnvelopeCodec::decodeSigned($this->raw_envelope);
    }
}
