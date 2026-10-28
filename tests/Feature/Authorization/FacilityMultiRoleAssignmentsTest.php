<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void { $this->withoutVite(); });

function assignedRole(Organization $organization, User $user, Facility $facility, string $role): int
{
    return DB::table('organization_staff_facilities')->insertGetId([
        'organization_id' => $organization->id, 'user_id' => $user->id,
        'facility_id' => $facility->id, 'role' => $role,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('combines facility admin and clinician permissions without granting treatment to other admins', function (): void {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $plainAdmin = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $organization->users()->attach($admin, ['role' => 'staff', 'status' => 'active']);
    $organization->users()->attach($plainAdmin, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    assignedRole($organization, $admin, $facility, 'facility_admin');
    assignedRole($organization, $plainAdmin, $facility, 'facility_admin');
    $url = route('organization.admin.staff.facility-roles.update', $admin->id);
    $this->patch($url, ['facility_id' => $facility->id, 'primary_role' => 'facility_admin', 'additional_roles' => ['clinician']])
        ->assertRedirect()->assertSessionHasNoErrors();
    $permissions = app(FacilityPermissions::class);
    expect($permissions->allows($admin, $facility, FacilityPermissions::MANAGE_CLIENTS))->toBeTrue()
        ->and($permissions->allows($admin, $facility, FacilityPermissions::MANAGE_ALERTS))->toBeTrue()
        ->and($permissions->allows($plainAdmin, $facility, FacilityPermissions::MANAGE_ALERTS))->toBeFalse()
        ->and(DB::table('facility_role_change_events')->where('user_id', $admin->id)->count())->toBe(1);
});

it('keeps additional roles restricted to their assigned facility', function (): void {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $organization->users()->attach($staff, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $first = Facility::factory()->create(['organization_id' => $organization->id]);
    $second = Facility::factory()->create(['organization_id' => $organization->id]);
    assignedRole($organization, $staff, $first, 'staff');
    assignedRole($organization, $staff, $second, 'staff');
    $this->patch(route('organization.admin.staff.facility-roles.update', $staff->id), [
        'facility_id' => $first->id, 'primary_role' => 'staff', 'additional_roles' => ['clinician'],
    ])->assertRedirect();
    expect(app(FacilityPermissions::class)->allows($staff, $first, FacilityPermissions::MANAGE_ALERTS))->toBeTrue()
        ->and(app(FacilityPermissions::class)->allows($staff, $second, FacilityPermissions::MANAGE_ALERTS))->toBeFalse();
});

it('lets only owners appoint facility owners and preserves the last active owner', function (): void {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $facilityOwner = User::factory()->create();
    $replacement = User::factory()->create();
    foreach ([[$owner, 'owner'], [$admin, 'admin'], [$facilityOwner, 'staff'], [$replacement, 'staff']] as [$user, $role]) {
        $organization->users()->attach($user, ['role' => $role, 'status' => 'active']);
    }
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    assignedRole($organization, $facilityOwner, $facility, 'facility_owner');
    assignedRole($organization, $replacement, $facility, 'staff');
    $endpoint = route('organization.admin.staff.facility-roles.update', $replacement->id);
    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $this->patch($endpoint, ['facility_id' => $facility->id, 'primary_role' => 'facility_owner', 'additional_roles' => []])->assertForbidden();
    $this->patch(route('organization.admin.staff.facility-roles.update', $facilityOwner->id), [
        'facility_id' => $facility->id, 'primary_role' => 'staff', 'additional_roles' => [],
    ])->assertForbidden();
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $this->patch(route('organization.admin.staff.facility-roles.update', $facilityOwner->id), [
        'facility_id' => $facility->id, 'primary_role' => 'staff', 'additional_roles' => [],
    ])->assertSessionHasErrors('role');
    $this->patch($endpoint, ['facility_id' => $facility->id, 'primary_role' => 'facility_owner', 'additional_roles' => []])->assertRedirect();
    $this->patch(route('organization.admin.staff.facility-roles.update', $facilityOwner->id), [
        'facility_id' => $facility->id, 'primary_role' => 'staff', 'additional_roles' => [],
    ])->assertRedirect();
    expect(DB::table('organization_staff_facilities')->where('facility_id', $facility->id)
        ->where('user_id', $replacement->id)->value('role'))->toBe('facility_owner');
});

it('rejects self assignment and leadership privilege through secondary roles', function (): void {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $staff = User::factory()->create();
    $organization->users()->attach($admin, ['role' => 'admin', 'status' => 'active']);
    $organization->users()->attach($staff, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    assignedRole($organization, $admin, $facility, 'staff');
    assignedRole($organization, $staff, $facility, 'staff');
    $endpoint = route('organization.admin.staff.facility-roles.update', $staff->id);
    $this->patch($endpoint, ['facility_id' => $facility->id, 'primary_role' => 'staff', 'additional_roles' => ['facility_owner']])->assertSessionHasErrors();
    $this->patch(route('organization.admin.staff.facility-roles.update', $admin->id), [
        'facility_id' => $facility->id, 'primary_role' => 'staff', 'additional_roles' => [],
    ])->assertForbidden();
});


it('does not allow facility admins to appoint an owner through either roles route or staff addition', function (): void {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $staff = User::factory()->create();
    $newStaff = User::factory()->create();
    foreach ([$admin, $staff, $newStaff] as $user) {
        $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    }
    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    assignedRole($organization, $admin, $facility, 'facility_admin');
    assignedRole($organization, $staff, $facility, 'staff');
    $this->withSession([\App\Support\FacilityContext::SESSION_KEY => $facility->id]);

    $this->get(route('facility.staff.index'))->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
        ->component('facilities/StaffRoster')
        ->where('canAssignOwner', false)
        ->where('actorOrganizationRole', 'staff')
        ->where('actorFacilityRole', 'facility_admin')
        ->etc());
    $this->get(route('facility.staff.roles.edit', [$facility->id, $staff->id]))
        ->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('facilities/StaffRoles')->where('canAssignOwner', false)->etc());

    $payload = ['facility_id' => $facility->id, 'primary_role' => 'facility_owner', 'additional_roles' => []];
    $this->patch(route('facility.staff.roles.update', [$facility->id, $staff->id]), $payload)->assertForbidden();
    $this->patch(route('organization.admin.staff.facility-roles.update', $staff->id), $payload)->assertForbidden();
    $this->post(route('facility.staff.assignments.store'), ['user_id' => $newStaff->id, 'role' => 'facility_owner'])
        ->assertForbidden();

    expect(DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
        ->where('facility_id', $facility->id)->where('user_id', $staff->id)->value('role'))->toBe('staff');
    expect(DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
        ->where('facility_id', $facility->id)->where('user_id', $newStaff->id)->exists())->toBeFalse();
});
