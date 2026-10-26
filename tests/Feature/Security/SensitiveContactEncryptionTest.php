<?php

use App\Models\ContactMethod;
use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('encrypts person notes and client contact values while preserving authorized model reads', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);

    $person = Person::create([
        'first_name' => 'Test', 'last_name' => 'Client', 'person_type' => 'client',
        'notes' => 'Confidential client background',
    ]);
    $contact = $person->contactMethods()->create([
        'kind' => 'address', 'label' => 'Home', 'is_primary' => true,
        'value' => 'private@example.test', 'line_1' => '100 Private Street',
        'line_2' => 'Apartment 4', 'city' => 'Winchester', 'state' => 'VA',
        'postal_code' => '22601', 'country' => 'US',
    ]);

    expect($person->fresh()->notes)->toBe('Confidential client background');
    expect(DB::table('people')->where('id', $person->id)->value('notes'))
        ->not->toContain('Confidential client background');

    $stored = DB::table('contact_methods')->where('id', $contact->id)->first();
    foreach (['value', 'line_1', 'line_2', 'city', 'state', 'postal_code'] as $field) {
        expect($stored->{$field})->not->toBe($contact->{$field});
        expect($contact->fresh()->{$field})->toBe($contact->{$field});
    }
    expect($contact->fresh()->country)->toBe('US');
});

it('does not encrypt identity search columns or contact classifications', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);

    $person = Person::create(['first_name' => 'Searchable', 'last_name' => 'Client', 'person_type' => 'client']);
    $person->contactMethods()->create(['kind' => 'phone', 'label' => 'Mobile', 'value' => '555-1234']);

    expect($person->fresh()->first_name)->toBe('Searchable');
    expect(DB::table('people')->where('id', $person->id)->value('first_name'))->not->toBe('Searchable');
    expect(ContactMethod::where('kind', 'phone')->firstOrFail()->value)->toBe('555-1234');
});
