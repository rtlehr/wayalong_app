<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function detailsActor(Organization $organization, string $role = 'owner'): User
{
    $actor = User::factory()->create();
    $actor->organizations()->attach($organization, ['role' => $role, 'status' => 'active']);
    test()->actingAs($actor)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    return $actor;
}

it('only offers the current organizations users for staff selection', function () {
    $org = Organization::factory()->create();
    detailsActor($org);
    $local = User::factory()->create();
    $local->organizations()->attach($org, ['role' => 'staff', 'status' => 'active']);
    $outside = User::factory()->create();
    $outside->organizations()->attach(Organization::factory()->create(), ['role' => 'staff', 'status' => 'active']);

    $this->get(route('organization.admin.staff.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('organizations/StaffForm')
        ->has('eligibleUsers', 2)
        ->etc());
});

it('saves staff details and only this organizations facility assignments', function () {
    $org = Organization::factory()->create();
    $owner = detailsActor($org);
    $member = User::factory()->create();
    $member->organizations()->attach($org, ['role' => 'clinician', 'status' => 'active']);
    $facility = Facility::factory()->create(['organization_id' => $org->id]);
    $otherOrganization = Organization::factory()->create();
    detailsActor($otherOrganization);
    $outside = Facility::factory()->create(['organization_id' => $otherOrganization->id]);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id]);

    $url = route('organization.admin.staff.details.update', $member->id);
    $this->patch($url, ['job_title' => 'Counselor', 'staff_type' => 'Clinical', 'credentials' => 'LPC', 'notes' => '', 'facility_ids' => [$outside->id]])
        ->assertSessionHasErrors('facility_ids.0');
    $this->assertDatabaseMissing('organization_staff_details', ['organization_id' => $org->id, 'user_id' => $member->id]);

    $this->patch($url, ['job_title' => 'Counselor', 'staff_type' => 'Clinical', 'credentials' => 'LPC', 'notes' => '', 'facility_ids' => [$facility->id]])
        ->assertRedirect(route('organization.admin.staff.edit', $member->id));
    $this->assertDatabaseHas('organization_staff_details', ['organization_id' => $org->id, 'user_id' => $member->id, 'job_title' => 'Counselor']);
    $this->assertDatabaseHas('organization_staff_facilities', ['organization_id' => $org->id, 'user_id' => $member->id, 'facility_id' => $facility->id]);
    $this->get(route('organization.admin.staff.edit', $member->id))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('staffDetails.job_title', 'Counselor')->where('assignedFacilities.0', $facility->id));
});

it('does not permit cross organization changes or staff editing', function () {
    $org = Organization::factory()->create();
    detailsActor($org);
    $outsider = User::factory()->create();
    $outsider->organizations()->attach(Organization::factory()->create(), ['role' => 'staff', 'status' => 'active']);
    $payload = ['facility_ids' => []];
    $this->patch(route('organization.admin.staff.details.update', $outsider->id), $payload)->assertNotFound();

    $staff = User::factory()->create();
    $staff->organizations()->attach($org, ['role' => 'staff', 'status' => 'active']);
    test()->actingAs($staff);
    $this->patch(route('organization.admin.staff.details.update', $staff->id), $payload)->assertForbidden();
});
