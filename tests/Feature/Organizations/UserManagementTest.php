<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function userManager(Organization $organization, string $role = 'owner'): User
{
    $owner = User::factory()->create();
    $owner->organizations()->attach($organization, ['role' => $role, 'status' => 'active']);
    test()->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    return $owner;
}

it('shows only current organization users and their contact forms', function () {
    $organization = Organization::factory()->create();
    userManager($organization);
    $member = User::factory()->create();
    $member->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $outside = User::factory()->create();
    $outside->organizations()->attach(Organization::factory()->create(), ['role' => 'staff', 'status' => 'active']);

    $this->get(route('organization.admin.users.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('organizations/Users')->has('users.data', 2));
    $this->get(route('organization.admin.users.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('organizations/UserForm')->where('mode', 'create'));
    $this->get(route('organization.admin.users.edit', $member->id))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('organizations/UserForm')->where('mode', 'edit'));
    $this->get(route('organization.admin.users.show', $outside->id))->assertNotFound();
});

it('updates organization profile and only the current organizations facility assignments', function () {
    $organization = Organization::factory()->create();
    userManager($organization);
    $person = User::factory()->create();
    $person->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    $other = Organization::factory()->create();
    $person->organizations()->attach($other, ['role' => 'staff', 'status' => 'active']);
    userManager($other);
    $otherFacility = Facility::factory()->create(['organization_id' => $other->id]);
    $this->actingAs($person); // restore first organization's administrator below
    $owner = $organization->users()->wherePivot('role', 'owner')->firstOrFail();
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);

    $data = ['name' => 'Updated Person', 'email' => $person->email, 'phone' => '555-0101', 'mobile_phone' => '555-0102',
        'address_line_1' => '100 Test Road', 'address_line_2' => '', 'city' => 'Winchester', 'state' => 'VA', 'postal_code' => '22601'];
    $this->patch(route('organization.admin.users.update', $person->id), $data + ['facility_ids' => [$otherFacility->id]])
        ->assertSessionHasErrors('facility_ids');
    $this->patch(route('organization.admin.users.update', $person->id), $data + ['facility_ids' => [$facility->id]])
        ->assertRedirect(route('organization.admin.users.edit', $person->id));
    $this->assertDatabaseHas('organization_user_profiles', ['organization_id' => $organization->id, 'user_id' => $person->id, 'phone' => '555-0101', 'city' => 'Winchester']);
    $this->assertDatabaseHas('organization_staff_facilities', ['organization_id' => $organization->id,
        'facility_id' => $facility->id, 'user_id' => $person->id]);
    $this->assertDatabaseMissing('organization_staff_facilities', ['organization_id' => $organization->id,
        'facility_id' => $otherFacility->id, 'user_id' => $person->id]);
});

it('creates one shared user account with an organization membership', function () {
    Notification::fake();
    $organization = Organization::factory()->create();
    userManager($organization);
    $this->post(route('organization.admin.users.store'), [
        'name' => 'New Person', 'email' => 'new.user@example.com', 'phone' => '555-0111', 'facility_ids' => [],
    ])->assertSessionHasNoErrors()->assertRedirect();
    $user = User::query()->where('email', 'new.user@example.com')->firstOrFail();
    $this->assertDatabaseHas('organization_user', ['organization_id' => $organization->id, 'user_id' => $user->id]);
    $this->assertDatabaseCount('users', 2);
});

it('removes only a membership and protects shared identity', function () {
    $organization = Organization::factory()->create();
    userManager($organization);
    $other = Organization::factory()->create();
    $person = User::factory()->create();
    $person->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $person->organizations()->attach($other, ['role' => 'staff', 'status' => 'active']);
    $this->delete(route('organization.admin.users.destroy', $person->id))
        ->assertRedirect(route('organization.admin.users.index'));
    $this->assertDatabaseHas('users', ['id' => $person->id]);
    $this->assertDatabaseHas('organization_user', ['organization_id' => $other->id, 'user_id' => $person->id]);
    $this->assertDatabaseMissing('organization_user', ['organization_id' => $organization->id, 'user_id' => $person->id]);
});

it('prevents ordinary staff from maintaining shared users', function () {
    $organization = Organization::factory()->create();
    $person = userManager($organization, 'staff');
    $this->get(route('organization.admin.users.index'))->assertForbidden();
    $this->get(route('organization.admin.users.create'))->assertForbidden();
    $this->patch(route('organization.admin.users.update', $person->id), [])->assertForbidden();
    $this->delete(route('organization.admin.users.destroy', $person->id))->assertForbidden();
});

it('filters staff by facility and facility delete leaves user and other assignments intact', function () {
    $organization = Organization::factory()->create();
    userManager($organization);
    $a = Facility::factory()->create(['organization_id' => $organization->id]);
    $b = Facility::factory()->create(['organization_id' => $organization->id]);
    $person = User::factory()->create();
    $person->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    foreach ([$a, $b] as $facility) {
        \Illuminate\Support\Facades\DB::table('organization_staff_facilities')->insert([
            'organization_id' => $organization->id, 'facility_id' => $facility->id, 'user_id' => $person->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->get(route('organization.admin.staff.index', ['facility_id' => $a->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.facility_id', $a->id)->has('members.data', 1));
    $this->delete(route('organization.admin.staff.destroy', ['member' => $person->id, 'facility_id' => $a->id]))->assertRedirect();
    $this->assertDatabaseHas('organization_staff_facilities', ['user_id' => $person->id, 'facility_id' => $b->id]);
    $this->assertDatabaseMissing('organization_staff_facilities', ['user_id' => $person->id, 'facility_id' => $a->id]);
    $this->assertDatabaseHas('organization_user', ['organization_id' => $organization->id, 'user_id' => $person->id]);
});

it('saves facility-specific staff details independently of other facilities', function () {
    $organization = Organization::factory()->create();
    userManager($organization);
    $person = User::factory()->create();
    $person->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $a = Facility::factory()->create(['organization_id' => $organization->id]);
    $b = Facility::factory()->create(['organization_id' => $organization->id]);
    foreach ([$a, $b] as $facility) {
        \Illuminate\Support\Facades\DB::table('organization_staff_facilities')->insert([
            'organization_id' => $organization->id, 'facility_id' => $facility->id, 'user_id' => $person->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->patch(route('organization.admin.staff.details.update', $person->id), [
        'facility_id' => $a->id, 'facility_ids' => [$a->id, $b->id], 'job_title' => 'Therapist',
    ])->assertRedirect();
    $this->assertDatabaseHas('facility_staff_profiles', ['facility_id' => $a->id, 'user_id' => $person->id, 'job_title' => 'Therapist']);
    $this->assertDatabaseMissing('facility_staff_profiles', ['facility_id' => $b->id, 'user_id' => $person->id]);
    $this->get(route('organization.admin.staff.edit', ['member' => $person->id, 'facility_id' => $a->id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('facilityStaffDetails.job_title', 'Therapist'));
});
