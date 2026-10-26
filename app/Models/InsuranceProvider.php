<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class InsuranceProvider extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['name', 'payer_code', 'phone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
