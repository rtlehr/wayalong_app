<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAlert extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['client_enrollment_id', 'created_by', 'category', 'priority', 'title', 'details', 'resolved_at', 'resolved_by'];

    protected function casts(): array
    {
        return ['details' => 'encrypted', 'resolved_at' => 'datetime'];
    }

    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ClientAlertEvent::class, 'client_alert_id')->orderBy('id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(ClientEnrollment::class, 'client_enrollment_id');
    }
}
