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

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }
}
