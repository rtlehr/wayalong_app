<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ClientBillingPayment extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'person_id', 'facility_id', 'charge_id', 'created_by', 'reverses_payment_id',
        'received_on', 'payer_type', 'method', 'amount_cents', 'reference', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date:Y-m-d', 'amount_cents' => 'integer',
            'reference' => 'encrypted', 'notes' => 'encrypted',
        ];
    }
}
