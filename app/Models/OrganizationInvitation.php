<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationInvitation extends Model
{
    protected $fillable = ['organization_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'facility_id', 'declined_at', 'decline_reason'];
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'declined_at' => 'datetime'];
    }

    public function facility(): BelongsTo { return $this->belongsTo(Facility::class); }
    public function inviter(): BelongsTo { return $this->belongsTo(User::class, 'invited_by'); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function isValid(): bool
    {
        return $this->accepted_at === null && $this->declined_at === null && $this->expires_at->isFuture() && $this->organization->status === 'active';
    }
}
