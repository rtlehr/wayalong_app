<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ClientBillingCharge extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['person_id', 'facility_id', 'created_by', 'reversed_by', 'reverses_charge_id', 'service_on', 'description', 'amount_cents', 'responsibility', 'notes'];

    protected function casts(): array
    {
        return ['service_on' => 'date:Y-m-d', 'notes' => 'encrypted', 'amount_cents' => 'integer'];
    }
}
