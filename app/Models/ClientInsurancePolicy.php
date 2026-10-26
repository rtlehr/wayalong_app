<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientInsurancePolicy extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'person_id', 'insurance_provider_id', 'payer_name', 'coverage_order', 'member_id', 'group_number',
        'plan_name', 'subscriber_name', 'subscriber_relationship', 'effective_on',
        'expires_on', 'verification_status', 'verified_on', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'member_id' => 'encrypted', 'group_number' => 'encrypted', 'notes' => 'encrypted',
            'effective_on' => 'date:Y-m-d', 'expires_on' => 'date:Y-m-d', 'verified_on' => 'date:Y-m-d',
        ];
    }

    public function verifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InsuranceVerification::class, 'client_insurance_policy_id')->latest('checked_on')->latest('id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
