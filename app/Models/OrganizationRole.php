<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationRole extends Model
{
    protected $fillable = ['organization_id', 'name', 'permissions'];

    protected function casts(): array { return ['permissions' => 'array']; }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }

    public function getMembershipRoleAttribute(): string { return 'custom:'.$this->id; }
}
