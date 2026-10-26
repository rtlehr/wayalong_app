<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ClientClaimAdjustment extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'person_id', 'facility_id', 'claim_id', 'charge_id', 'created_by',
        'reverses_adjustment_id', 'adjusted_on', 'kind', 'amount_cents', 'reason',
    ];

    protected function casts(): array
    {
        return ['adjusted_on' => 'date:Y-m-d', 'amount_cents' => 'integer', 'reason' => 'encrypted'];
    }
}
