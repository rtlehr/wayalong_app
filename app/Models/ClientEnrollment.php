<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientEnrollment extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['facility_id', 'person_id', 'status', 'enrolled_at', 'discharged_at'];

    protected function casts(): array
    {
        return ['enrolled_at' => 'date', 'discharged_at' => 'date'];
    }

    public function shiftNotes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ClientShiftNote::class, 'client_enrollment_id');
    }

    public function latestShiftNote(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ClientShiftNote::class, 'client_enrollment_id')->latestOfMany();
    }

    public function alertHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ClientAlert::class, 'client_enrollment_id');
    }

    public function activeAlerts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ClientAlert::class, 'client_enrollment_id')->whereNull('resolved_at');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }
}
