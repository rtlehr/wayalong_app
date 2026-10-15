<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationInvitation extends Model
{
    protected $fillable = ['organization_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'];
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function isValid(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture() && $this->organization->status === 'active';
    }
}
