<?php

namespace App\Models;

use App\Casts\EncryptedBirthDate;
use App\Support\PersonNameSearch;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Person extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['facility_id', 'first_name', 'last_name', 'date_of_birth', 'person_type', 'notes'];

    protected function casts(): array
    {
        return ['first_name' => 'encrypted', 'last_name' => 'encrypted', 'date_of_birth' => EncryptedBirthDate::class, 'notes' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::saved(function (self $person): void {
            if ($person->wasRecentlyCreated || $person->wasChanged(['first_name', 'last_name'])) {
                PersonNameSearch::sync((int) $person->id, (int) $person->organization_id, $person->first_name, $person->last_name);
            }
        });
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contactMethods(): MorphMany
    {
        return $this->morphMany(ContactMethod::class, 'contactable');
    }
}
