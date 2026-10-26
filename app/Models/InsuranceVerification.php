<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceVerification extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['client_insurance_policy_id', 'verified_by', 'status', 'method', 'checked_on', 'reference_number', 'notes'];

    protected function casts(): array
    {
        return ['checked_on' => 'date:Y-m-d', 'reference_number' => 'encrypted', 'notes' => 'encrypted'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ClientInsurancePolicy::class, 'client_insurance_policy_id');
    }
}
