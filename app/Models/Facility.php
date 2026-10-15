<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\FacilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'code', 'status', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'phone', 'email', 'timezone'])]
class Facility extends Model
{
    /** @use HasFactory<FacilityFactory> */
    use HasFactory;
    use BelongsToOrganization;
}
