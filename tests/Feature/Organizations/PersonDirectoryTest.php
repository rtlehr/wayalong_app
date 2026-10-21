<?php

use App\Models\Organization;
use App\Models\Facility;
use App\Models\Person;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('creates a youth client without an email or login account', function () {
    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $owner->organizations()->attach($org, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $this->post(route('organization.admin.people.store'), ['first_name' => 'Youth', 'last_name' => 'Example', 'person_type' => 'client'])->assertRedirect();
    $person = Person::firstOrFail();
    expect($person->user_id)->toBeNull()->and($person->organization_id)->toBe($org->id);
    $this->assertDatabaseCount('users', 1);
    $this->get(route('organization.admin.people.show', $person))->assertOk()->assertInertia(fn (Assert $page) => $page->component('organizations/PersonForm'));
});

it('blocks outsiders and cross-organization facilities', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $adminA = User::factory()->create();
    $adminB = User::factory()->create();
    $adminA->organizations()->attach($orgA, ['role' => 'owner', 'status' => 'active']);
    $adminB->organizations()->attach($orgB, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($adminB)->withSession([OrganizationContext::SESSION_KEY => $orgB->id]);
    $foreignFacility = Facility::factory()->create(['organization_id' => $orgB->id]);
    $foreignPerson = Person::create(['first_name' => 'Other', 'last_name' => 'Person', 'person_type' => 'client']);
    $this->actingAs($adminA)->withSession([OrganizationContext::SESSION_KEY => $orgA->id]);
    $this->get(route('organization.admin.people.show', $foreignPerson->id))->assertNotFound();
    $this->post(route('organization.admin.people.store'), ['first_name' => 'First', 'last_name' => 'Last', 'person_type' => 'client', 'facility_id' => $foreignFacility->id])->assertSessionHasErrors('facility_id');
    $this->assertDatabaseCount('people', 1);
});

it('keeps contact methods attached to the person, not to a login', function () {
    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $owner->organizations()->attach($org, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $this->post(route('organization.admin.people.store'), [
        'first_name' => 'Example', 'last_name' => 'Client', 'person_type' => 'client',
        'contacts' => [
            ['kind' => 'phone', 'label' => 'Home', 'is_primary' => true, 'value' => '555-0100'],
            ['kind' => 'phone', 'label' => 'Mobile', 'is_primary' => false, 'value' => '555-0101'],
        ],
    ])->assertSessionHasNoErrors();
    expect(Person::firstOrFail()->contactMethods()->count())->toBe(2);
});
