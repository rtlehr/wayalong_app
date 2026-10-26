<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ClientInsuranceClaimEvent extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['claim_id', 'created_by', 'status', 'notes'];

    protected function casts(): array
    {
        return ['notes' => 'encrypted'];
    }
}
