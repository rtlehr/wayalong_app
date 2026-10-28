<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function overrideAssigned(Organization $org, User $user, string $role = 'staff'): Facility
{
    $facility = Facility::factory()->create(['organization_id' => $org->id]);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $org->id, 'facility_id' => $facility->id,
        'user_id' => $user->id, 'role' => $role,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $facility;
}

it('lets owners grant deny and restore a member permission within a facility with a durable audit event', function () {
    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $clinician = User::factory()->create();
    $org->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $org->users()->attach($clinician, ['role' => 'clinician', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $facility = overrideAssigned($org, $clinician, 'clinician');
    expect(app(FacilityPermissions::class)->allows($clinician, $facility, FacilityPermissions::MANAGE_ALERTS))->toBeTrue();

    $endpoint = route('organization.admin.members.permissions.update', $clinician->id);
    $this->patch($endpoint, ['facility_id' => $facility->id, 'permission' => FacilityPermissions::MANAGE_ALERTS, 'decision' => 'deny'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(app(FacilityPermissions::class)->allows($clinician, $facility, FacilityPermissions::MANAGE_ALERTS))->toBeFalse();
    $this->patch($endpoint, ['facility_id' => $facility->id, 'permission' => FacilityPermissions::MANAGE_ALERTS, 'decision' => 'inherit'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(app(FacilityPermissions::class)->allows($clinician, $facility, FacilityPermissions::MANAGE_ALERTS))->toBeTrue();
    expect(DB::table('member_permission_override_events')->where('organization_id', $org->id)->where('user_id', $clinician->id)->count())->toBe(2);
});

it('refuses self overrides and unsupported high privilege permissions', function () {
    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $org->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $url = route('organization.admin.members.permissions.update', $owner->id);
    $this->patch($url, ['permission' => OrganizationPermissions::VIEW_SECURITY_AUDIT, 'decision' => 'allow'])->assertForbidden();
    expect(DB::table('member_permission_overrides')->count())->toBe(0);
});

it('lets a manager delegate only rights they possess at their assigned facility', function () {
    $org = Organization::factory()->create();
    $manager = User::factory()->create();
    $staff = User::factory()->create();
    $org->users()->attach($manager, ['role' => 'staff', 'status' => 'active']);
    $org->users()->attach($staff, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($manager)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $facility = overrideAssigned($org, $manager, 'facility_admin');
    // A Facility Admin has administrative client-view permission by default,
    // but does not inherit clinical alert-management permission.
    expect(app(FacilityPermissions::class)->allows($manager, $facility, FacilityPermissions::VIEW_CLIENTS))->toBeTrue();
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $org->id, 'facility_id' => $facility->id, 'user_id' => $staff->id,
        'role' => 'staff', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $url = route('facility.staff.permissions.update', [$facility->id, $staff->id]);
    $this->get(route('facility.staff.permissions.edit', [$facility->id, $staff->id]))
        ->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('facilities/StaffPermissions')
            ->where('effectivePermissions', fn ($effective) =>
                $effective[FacilityPermissions::VIEW_STAFF] === true
                && $effective[FacilityPermissions::VIEW_CLIENTS] === false)
            ->etc());
    $this->patch($url, ['permission' => FacilityPermissions::VIEW_CLIENTS, 'decision' => 'allow'])->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('facility.staff.permissions.edit', [$facility->id, $staff->id]))
        ->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('effectivePermissions', fn ($effective) => $effective[FacilityPermissions::VIEW_CLIENTS] === true)
            ->where('overrides', fn ($saved) => $saved[FacilityPermissions::VIEW_CLIENTS] === 'allow')
            ->etc());
    expect(app(FacilityPermissions::class)->allows($staff, $facility, FacilityPermissions::VIEW_CLIENTS))->toBeTrue();
    $this->patch($url, ['permission' => FacilityPermissions::MANAGE_ALERTS, 'decision' => 'allow'])->assertForbidden();
    $this->patch($url, ['permission' => OrganizationPermissions::VIEW_SECURITY_AUDIT, 'decision' => 'allow'])->assertForbidden();
    $this->patch(route('facility.staff.permissions.update', [$facility->id, $manager->id]), ['permission' => FacilityPermissions::VIEW_CLIENTS, 'decision' => 'allow'])->assertForbidden();
});

it('never carries a facility override to another facility or another organization', function () {
    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $org->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $org->users()->attach($staff, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $first = overrideAssigned($org, $staff);
    $second = overrideAssigned($org, $staff);
    $this->patch(route('organization.admin.members.permissions.update', $staff->id), [
        'facility_id' => $first->id, 'permission' => FacilityPermissions::VIEW_CLIENTS, 'decision' => 'allow',
    ])->assertRedirect();
    expect(app(FacilityPermissions::class)->allows($staff, $first, FacilityPermissions::VIEW_CLIENTS))->toBeTrue()
        ->and(app(FacilityPermissions::class)->allows($staff, $second, FacilityPermissions::VIEW_CLIENTS))->toBeFalse();
    $org->users()->updateExistingPivot($staff->id, ['status' => 'inactive']);
    expect(app(FacilityPermissions::class)->allows($staff, $first, FacilityPermissions::VIEW_CLIENTS))->toBeFalse();
});
