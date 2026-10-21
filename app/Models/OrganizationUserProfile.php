<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OrganizationUserProfile extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'display_name', 'phone', 'mobile_phone',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code',
    ];

    public function contactMethods(): MorphMany
    {
        return $this->morphMany(ContactMethod::class, 'contactable');
    }
}
