<?php
declare(strict_types=1);

namespace Fissible\AttestLaravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $anchor_id
 * @property string $chain_id
 * @property int $from_seq
 * @property int $to_seq
 * @property string $driver
 * @property string $claimed_by
 * @property \Illuminate\Support\Carbon $claimed_at
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?string $completed_envelope_id
 */
final class AttestAnchorClaim extends Model
{
    protected $table = 'attest_anchor_claims';
    public $timestamps = false;
    protected $primaryKey = 'anchor_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = [
        'from_seq' => 'integer',
        'to_seq' => 'integer',
        'claimed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
