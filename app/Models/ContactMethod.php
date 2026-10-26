<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContactMethod extends Model
{
    protected $fillable = ['kind', 'label', 'is_primary', 'value', 'line_1', 'line_2', 'city', 'state', 'postal_code', 'country'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'verified_at' => 'datetime',
            'value' => 'encrypted', 'line_1' => 'encrypted', 'line_2' => 'encrypted',
            'city' => 'encrypted', 'state' => 'encrypted', 'postal_code' => 'encrypted'];
    }

    public function contactable(): MorphTo
    {
        return $this->morphTo();
    }
}
