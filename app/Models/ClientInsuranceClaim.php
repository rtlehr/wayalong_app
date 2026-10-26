<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientInsuranceClaim extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['person_id', 'facility_id', 'charge_id', 'client_insurance_policy_id', 'created_by', 'status', 'claim_number', 'submitted_on', 'notes', 'adjudicated_on', 'allowed_amount_cents', 'insurer_paid_cents', 'patient_responsibility_cents'];

    protected function casts(): array
    {
        return ['submitted_on' => 'date:Y-m-d', 'adjudicated_on' => 'date:Y-m-d', 'notes' => 'encrypted', 'allowed_amount_cents' => 'integer', 'insurer_paid_cents' => 'integer', 'patient_responsibility_cents' => 'integer'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ClientInsuranceClaimEvent::class, 'claim_id')->orderBy('id');
    }
}
