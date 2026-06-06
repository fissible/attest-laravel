<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-side query convenience over attest_envelopes. The store owns
 * all writes; do NOT call ->save() to add envelopes.
 *
 * @property int $id
 * @property string $chain_id
 * @property int $sequence
 * @property string $envelope_id
 * @property ?string $prev_hash
 * @property string $self_hash
 * @property string $key_id
 * @property string $type
 * @property string $raw_envelope
 * @property \Illuminate\Support\Carbon $created_at
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
}
