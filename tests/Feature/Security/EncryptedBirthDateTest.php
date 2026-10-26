<?php

use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('encrypts dates of birth without changing the date exposed to authorized application code', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);

    $person = Person::create([
        'first_name' => 'Sample', 'last_name' => 'Client', 'person_type' => 'client',
        'date_of_birth' => '2008-03-12',
    ]);

    $stored = DB::table('people')->where('id', $person->id)->value('date_of_birth');
    expect($stored)->not->toBe('2008-03-12');
    expect(Crypt::decryptString($stored))->toBe('2008-03-12');
    expect($person->fresh()->date_of_birth->toDateString())->toBe('2008-03-12');
    expect($person->fresh()->toArray()['date_of_birth'])->toBeString();

    $person->update(['date_of_birth' => '2008-04-15']);
    expect($person->fresh()->date_of_birth->toDateString())->toBe('2008-04-15');
    expect(DB::table('people')->where('id', $person->id)->value('date_of_birth'))->not->toBe('2008-04-15');

    $person->update(['date_of_birth' => null]);
    expect($person->fresh()->date_of_birth)->toBeNull();
});
