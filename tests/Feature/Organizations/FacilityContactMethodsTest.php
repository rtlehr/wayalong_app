<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function facilityContactOwner(): Organization
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $user->organizations()->attach($organization, ['role' => 'owner', 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    return $organization;
}

function facilityContactPayload(array $contacts): array
{
    return ['name' => 'North Center', 'code' => 'NORTH', 'status' => 'active', 'contacts' => $contacts];
}

it('creates multiple facility phones and emails and mirrors selected primaries', function () {
    facilityContactOwner();
    $contacts = [
        ['kind' => 'phone', 'label' => 'Front desk', 'value' => '555-1000', 'is_primary' => false],
        ['kind' => 'phone', 'label' => 'After hours', 'value' => '555-2000', 'is_primary' => true],
        ['kind' => 'email', 'label' => 'Main', 'value' => 'main@example.test', 'is_primary' => true],
        ['kind' => 'email', 'label' => 'Billing', 'value' => 'billing@example.test', 'is_primary' => false],
    ];

    $this->post(route('organization.admin.facilities.store'), facilityContactPayload($contacts))->assertRedirect();
    $facility = Facility::firstOrFail();
    expect($facility->phone)->toBe('555-2000')
        ->and($facility->email)->toBe('main@example.test')
        ->and($facility->contactMethods()->count())->toBe(4)
        ->and($facility->contactMethods()->where('is_primary', true)->count())->toBe(2);

    $this->get(route('organization.admin.facilities.index'))->assertInertia(fn (Assert $page) => $page
        ->component('organizations/Facilities')
        ->has('facilities.0.contact_methods', 4));
});

it('edits facility contacts without duplicating entries and clears removed kinds', function () {
    facilityContactOwner();
    $this->post(route('organization.admin.facilities.store'), facilityContactPayload([
        ['kind' => 'phone', 'label' => 'Office', 'value' => '555-1000', 'is_primary' => true],
        ['kind' => 'email', 'label' => 'Office', 'value' => 'office@example.test', 'is_primary' => true],
    ]))->assertRedirect();
    $facility = Facility::firstOrFail();

    $this->patch(route('organization.admin.facilities.update', $facility->id), facilityContactPayload([
        ['kind' => 'phone', 'label' => 'Reception', 'value' => '555-3000', 'is_primary' => true],
    ]))->assertRedirect();
    expect($facility->fresh()->phone)->toBe('555-3000')
        ->and($facility->fresh()->email)->toBeNull()
        ->and($facility->contactMethods()->count())->toBe(1);
});

it('rejects duplicate primary contacts and preserves the old contacts', function () {
    facilityContactOwner();
    $this->post(route('organization.admin.facilities.store'), facilityContactPayload([
        ['kind' => 'phone', 'label' => 'Office', 'value' => '555-1000', 'is_primary' => true],
    ]))->assertRedirect();
    $facility = Facility::firstOrFail();

    $this->patch(route('organization.admin.facilities.update', $facility->id), facilityContactPayload([
        ['kind' => 'phone', 'label' => 'Office', 'value' => '555-2000', 'is_primary' => true],
        ['kind' => 'phone', 'label' => 'Other', 'value' => '555-3000', 'is_primary' => true],
    ]))->assertSessionHasErrors('contacts');
    expect($facility->fresh()->phone)->toBe('555-1000')
        ->and($facility->contactMethods()->count())->toBe(1);
});

it('still accepts legacy single phone and email submissions', function () {
    facilityContactOwner();
    $this->post(route('organization.admin.facilities.store'), [
        'name' => 'Legacy Center', 'code' => 'LEG', 'status' => 'active',
        'phone' => '555-4444', 'email' => 'legacy@example.test',
    ])->assertRedirect();
    $facility = Facility::firstOrFail();
    expect($facility->phone)->toBe('555-4444')
        ->and($facility->email)->toBe('legacy@example.test');
});
