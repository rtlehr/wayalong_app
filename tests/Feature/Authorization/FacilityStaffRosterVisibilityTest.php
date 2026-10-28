<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\FacilityContext;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void { $this->withoutVite(); });

function rosterVisibilityAssign(Organization $organization, Facility $facility, User $user, string $role): void
{
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id, 'facility_id' => $facility->id,
        'user_id' => $user->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('does not advertise permission or role links when their edit routes reject the actor', function (): void {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    foreach ([$admin, $owner, $staff] as $person) {
        $organization->users()->attach($person, ['role' => 'staff', 'status' => 'active']);
    }
    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    rosterVisibilityAssign($organization, $facility, $admin, 'facility_admin');
    rosterVisibilityAssign($organization, $facility, $owner, 'facility_owner');
    rosterVisibilityAssign($organization, $facility, $staff, 'staff');
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id]);

    $this->get(route('facility.staff.index'))->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
        ->component('facilities/StaffRoster')
        ->where('staff', function ($rows) use ($admin, $owner, $staff): bool {
            $rows = collect($rows);
            return $rows->firstWhere('id', $admin->id)['canEditPermissions'] === false
                && $rows->firstWhere('id', $owner->id)['canEditPermissions'] === false
                && $rows->firstWhere('id', $owner->id)['canEditRoles'] === false
                && $rows->firstWhere('id', $staff->id)['canEditPermissions'] === true
                && $rows->firstWhere('id', $staff->id)['canEditRoles'] === true;
        })->etc());

    $this->get(route('facility.staff.permissions.edit', [$facility->id, $owner->id]))->assertForbidden();
});

it('does not let ordinary staff manage other staff but labels organization ownership separately', function (): void {
    $organization = Organization::factory()->create();
    $staffActor = User::factory()->create();
    $target = User::factory()->create();
    $orgOwner = User::factory()->create();
    foreach ([$staffActor, $target] as $person) {
        $organization->users()->attach($person, ['role' => 'staff', 'status' => 'active']);
    }
    $organization->users()->attach($orgOwner, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($staffActor)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    rosterVisibilityAssign($organization, $facility, $staffActor, 'staff');
    rosterVisibilityAssign($organization, $facility, $target, 'staff');
    rosterVisibilityAssign($organization, $facility, $orgOwner, 'staff');
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id]);

    $this->get(route('facility.staff.index'))->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
        ->component('facilities/StaffRoster')
        ->where('canManageStaffPermissions', false)
        ->where('canAssignRoles', false)
        ->where('staff', fn ($rows) => collect($rows)->every(fn ($row) => ! $row['canEditPermissions'] && ! $row['canEditRoles'] && ! $row['canRemove']))
        ->etc());
    $this->get(route('facility.staff.roles.edit', [$facility->id, $target->id]))->assertForbidden();
    $this->get(route('facility.staff.permissions.edit', [$facility->id, $target->id]))->assertForbidden();
    $this->post(route('facility.staff.assignments.store'), ['user_id' => $target->id, 'role' => 'staff'])->assertForbidden();
    $this->delete(route('facility.staff.assignments.destroy', $target->id))->assertForbidden();

    $this->actingAs($orgOwner)->withSession([OrganizationContext::SESSION_KEY => $organization->id, FacilityContext::SESSION_KEY => $facility->id]);
    $this->get(route('facility.staff.index'))->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
        ->component('facilities/StaffRoster')
        ->where('staff', fn ($rows) => collect($rows)->firstWhere('id', $orgOwner->id)['organizationRole'] === 'owner')
        ->etc());
});


it('explains protected and self-management rows without advertising forbidden actions', function (): void {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $facilityOwner = User::factory()->create();
    $organizationOwner = User::factory()->create();
    $organization->users()->attach($actor, ['role' => 'staff', 'status' => 'active']);
    $organization->users()->attach($facilityOwner, ['role' => 'staff', 'status' => 'active']);
    $organization->users()->attach($organizationOwner, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($actor)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    rosterVisibilityAssign($organization, $facility, $actor, 'facility_admin');
    rosterVisibilityAssign($organization, $facility, $facilityOwner, 'facility_owner');
    rosterVisibilityAssign($organization, $facility, $organizationOwner, 'staff');

    $this->actingAs($actor)->withSession([
        OrganizationContext::SESSION_KEY => $organization->id,
        FacilityContext::SESSION_KEY => $facility->id,
    ])->get(route('facility.staff.index'))->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
        ->where('staff', function ($rows) use ($actor, $facilityOwner, $organizationOwner): bool {
            $rows = collect($rows);
            return $rows->firstWhere('id', $actor->id)['managementStatus'] === 'Cannot modify your own permissions'
                && $rows->firstWhere('id', $facilityOwner->id)['managementStatus'] === 'Protected — Facility Owner'
                && $rows->firstWhere('id', $organizationOwner->id)['managementStatus'] === 'Protected — Organization Owner'
                && $rows->every(function ($row): bool {
                    $hasAction = $row['canEditPermissions'] || $row['canEditRoles'] || $row['canRemove'];
                    return $hasAction ? $row['managementStatus'] === null : is_string($row['managementStatus']) && $row['managementStatus'] !== '';
                });
        })->etc());
});

it('lets an organization owner see a permitted role edit link for organization leadership', function (): void {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $organization->users()->attach($actor, ['role' => 'owner', 'status' => 'active']);
    $organization->users()->attach($target, ['role' => 'admin', 'status' => 'active']);
    $this->actingAs($actor)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    rosterVisibilityAssign($organization, $facility, $actor, 'staff');
    rosterVisibilityAssign($organization, $facility, $target, 'staff');

    $this->actingAs($actor)->withSession([
        OrganizationContext::SESSION_KEY => $organization->id,
        FacilityContext::SESSION_KEY => $facility->id,
    ])->get(route('facility.staff.index'))->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
        ->where('staff', function ($rows) use ($target): bool {
            $row = collect($rows)->firstWhere('id', $target->id);
            return $row['canEditRoles'] === true && $row['canEditPermissions'] === false
                && $row['managementStatus'] === null;
        })->etc());
    $this->get(route('facility.staff.roles.edit', [$facility->id, $target->id]))->assertOk();
});

it('shows organization-scoped primary contacts for assigned staff without leaking another organization profile', function (): void {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $actor = User::factory()->create();
    $staff = User::factory()->create(['email' => 'login@example.test']);
    $organization->users()->attach($actor, ['role' => 'owner', 'status' => 'active']);
    $organization->users()->attach($staff, ['role' => 'staff', 'status' => 'active']);
    $otherOrganization->users()->attach($staff, ['role' => 'staff', 'status' => 'active']);

    $this->actingAs($actor)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    rosterVisibilityAssign($organization, $facility, $actor, 'staff');
    rosterVisibilityAssign($organization, $facility, $staff, 'clinician');
    $profile = \App\Models\OrganizationUserProfile::create([
        'organization_id' => $organization->id, 'user_id' => $staff->id, 'phone' => '555-0000',
    ]);
    $profile->contactMethods()->create(['kind' => 'phone', 'label' => 'Work', 'is_primary' => true, 'value' => '555-1234']);
    $profile->contactMethods()->create(['kind' => 'email', 'label' => 'Work', 'is_primary' => true, 'value' => 'work@example.test']);
    $otherProfile = \App\Models\OrganizationUserProfile::create([
        'organization_id' => $otherOrganization->id, 'user_id' => $staff->id, 'phone' => '555-9999',
    ]);
    $otherProfile->contactMethods()->create(['kind' => 'email', 'label' => 'Private', 'is_primary' => true, 'value' => 'other@example.test']);

    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])
        ->get(route('facility.staff.index'))->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('facilities/StaffRoster')
            ->where('staff', function ($rows) use ($staff): bool {
                $row = collect($rows)->firstWhere('id', $staff->id);
                return $row['phone'] === '555-1234' && $row['email'] === 'work@example.test';
            })->etc());

    $profile->contactMethods()->delete();
    $this->get(route('facility.staff.index'))->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
        ->where('staff', function ($rows) use ($staff): bool {
            $row = collect($rows)->firstWhere('id', $staff->id);
            return $row['phone'] === '555-0000' && $row['email'] === 'login@example.test';
        })->etc());
});
