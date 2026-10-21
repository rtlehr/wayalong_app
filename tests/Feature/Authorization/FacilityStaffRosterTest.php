<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\FacilityContext;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () { $this->withoutVite(); });

function rosterAssignment(Organization $organization, User $user, Facility $facility, string $role): void
{
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id, 'user_id' => $user->id,
        'facility_id' => $facility->id, 'role' => $role,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('shows only active staff assigned to the selected facility', function () {
    $organization = Organization::factory()->create();
    $viewer = User::factory()->create();
    $colleague = User::factory()->create();
    $elsewhere = User::factory()->create();
    $inactive = User::factory()->create();
    foreach ([$viewer, $colleague, $elsewhere, $inactive] as $user) {
        $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    }
    $this->actingAs($viewer)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $selected = Facility::factory()->create(['organization_id' => $organization->id]);
    $other = Facility::factory()->create(['organization_id' => $organization->id]);
    rosterAssignment($organization, $viewer, $selected, 'clinician');
    rosterAssignment($organization, $colleague, $selected, 'staff');
    rosterAssignment($organization, $elsewhere, $other, 'clinician');
    rosterAssignment($organization, $inactive, $selected, 'staff');
    $organization->users()->updateExistingPivot($inactive->id, ['status' => 'inactive']);

    $this->withSession([FacilityContext::SESSION_KEY => $selected->id])
        ->get(route('facility.staff.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('facilities/StaffRoster')
            ->where('facility.id', $selected->id)
            ->has('staff', 2)
            ->etc());
});

it('requires a selected facility and an actual staff assignment', function () {
    $organization = Organization::factory()->create();
    $viewer = User::factory()->create();
    $organization->users()->attach($viewer, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($viewer)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    $this->get(route('facility.staff.index'))->assertForbidden();
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])
        ->get(route('facility.staff.index'))->assertForbidden();
});

it('prevents a facility role without staff-view permission from accessing the roster', function () {
    $organization = Organization::factory()->create();
    $viewer = User::factory()->create();
    $organization->users()->attach($viewer, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($viewer)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    rosterAssignment($organization, $viewer, $facility, 'custom:123');
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])
        ->get(route('facility.staff.index'))->assertForbidden();
});

it('revokes roster access when facility or membership becomes inactive', function () {
    $organization = Organization::factory()->create();
    $viewer = User::factory()->create();
    $organization->users()->attach($viewer, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($viewer)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    rosterAssignment($organization, $viewer, $facility, 'clinician');
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])->get(route('facility.staff.index'))->assertOk();
    $organization->users()->updateExistingPivot($viewer->id, ['status' => 'inactive']);
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])->get(route('facility.staff.index'))->assertForbidden();
    $organization->users()->updateExistingPivot($viewer->id, ['status' => 'active']);
    $facility->update(['status' => 'inactive']);
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])->get(route('facility.staff.index'))->assertForbidden();
});
