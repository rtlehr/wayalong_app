<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\FacilityContext;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () { $this->withoutVite(); });

function assignFacilityRole(Organization $organization, User $user, string $role): Facility
{
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id, 'user_id' => $user->id,
        'facility_id' => $facility->id, 'role' => $role,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $facility;
}

it('enforces different permissions on separate facility assignments for one identity', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $clinical = assignFacilityRole($organization, $user, 'clinician');
    $support = assignFacilityRole($organization, $user, 'staff');
    $permissions = app(FacilityPermissions::class);

    expect($permissions->allows($user, $clinical, FacilityPermissions::VIEW_CLIENTS))->toBeTrue()
        ->and($permissions->allows($user, $clinical, FacilityPermissions::VIEW_CLINICAL_CASELOAD))->toBeTrue()
        ->and($permissions->allows($user, $clinical, FacilityPermissions::MANAGE_CLIENTS))->toBeFalse()
        ->and($permissions->allows($user, $clinical, FacilityPermissions::MANAGE_ALERTS))->toBeTrue()
        ->and($permissions->allows($user, $support, FacilityPermissions::VIEW_STAFF))->toBeTrue()
        ->and($permissions->allows($user, $support, FacilityPermissions::VIEW_CLIENTS))->toBeFalse();

    $this->withSession([FacilityContext::SESSION_KEY => $support->id])->get(route('dashboard'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('activeFacilityRole', 'staff')
            ->where('activeFacilityPermissions', [FacilityPermissions::VIEW_STAFF])->etc());
});

it('rejects permissions for unassigned and other organization facilities', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'clinician', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $unassigned = Facility::factory()->create(['organization_id' => $organization->id]);

    // Create the other tenant's facility as an authorized member of that tenant.
    // The organization guard must remain active during fixture creation.
    $otherUser = User::factory()->create();
    $other->users()->attach($otherUser, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($otherUser);
    session()->put(OrganizationContext::SESSION_KEY, $other->id);
    $anotherOrganization = Facility::factory()->create(['organization_id' => $other->id]);

    // Restore the original tenant before evaluating permission boundaries.
    $this->actingAs($user);
    session()->put(OrganizationContext::SESSION_KEY, $organization->id);
    $permissions = app(FacilityPermissions::class);

    expect($permissions->allows($user, $unassigned, FacilityPermissions::VIEW_CLIENTS))->toBeFalse()
        ->and($permissions->allows($user, $anotherOrganization, FacilityPermissions::VIEW_CLIENTS))->toBeFalse();
});

it('revokes facility permissions immediately when membership or facility becomes inactive', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = assignFacilityRole($organization, $user, 'clinician');
    $permissions = app(FacilityPermissions::class);

    expect($permissions->allows($user, $facility, FacilityPermissions::VIEW_CLIENTS))->toBeTrue();
    $organization->users()->updateExistingPivot($user->id, ['status' => 'inactive']);
    expect($permissions->allows($user, $facility, FacilityPermissions::VIEW_CLIENTS))->toBeFalse();
    $organization->users()->updateExistingPivot($user->id, ['status' => 'active']);
    $facility->update(['status' => 'inactive']);
    expect($permissions->allows($user, $facility->fresh(), FacilityPermissions::VIEW_CLIENTS))->toBeFalse();
});

it('grants organization owners ordinary treatment access at their facilities', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = assignFacilityRole($organization, $owner, 'owner');
    $permissions = app(FacilityPermissions::class);

    expect($permissions->allows($owner, $facility, FacilityPermissions::VIEW_STAFF))->toBeTrue()
        ->and($permissions->allows($owner, $facility, FacilityPermissions::VIEW_CLIENTS))->toBeTrue()
        ->and($permissions->allows($owner, $facility, FacilityPermissions::MANAGE_CLIENTS))->toBeTrue()
        ->and($permissions->allows($owner, $facility, FacilityPermissions::VIEW_CLINICAL_CASELOAD))->toBeTrue()
        ->and($permissions->allows($owner, $facility, FacilityPermissions::MANAGE_ALERTS))->toBeTrue()
        ->and($permissions->allows($owner, $facility, FacilityPermissions::VIEW_SHIFT_NOTES))->toBeTrue()
        ->and($permissions->allows($owner, $facility, FacilityPermissions::WRITE_SHIFT_NOTES))->toBeTrue();
});


it('lets an organization owner administer and access ordinary treatment in every active facility without assignment', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $first = Facility::factory()->create(['organization_id' => $organization->id]);
    $second = Facility::factory()->create(['organization_id' => $organization->id]);
    $permissions = app(FacilityPermissions::class);

    foreach ([$first, $second] as $facility) {
        expect($permissions->allows($owner, $facility, FacilityPermissions::MANAGE_CLIENTS))->toBeTrue()
            ->and($permissions->allows($owner, $facility, FacilityPermissions::MANAGE_ALERTS))->toBeTrue()
            ->and($permissions->allows($owner, $facility, FacilityPermissions::VIEW_SHIFT_NOTES))->toBeTrue();

        // Exercise the authenticated facility-switch route rather than calling the
        // context service with a request outside the HTTP test lifecycle.
        $this->post(route('facilities.switch'), ['facility_id' => $facility->id])
            ->assertRedirect(route('dashboard'));
        expect((int) session(FacilityContext::SESSION_KEY))->toBe($facility->id);
    }
    $organization->users()->updateExistingPivot($owner->id, ['status' => 'inactive']);
    expect($permissions->allows($owner, $first, FacilityPermissions::MANAGE_ALERTS))->toBeFalse();
});

it('confines facility owner rights to their assigned facility and keeps facility admins nonclinical', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'staff', 'status' => 'active']);
    $organization->users()->attach($admin, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = assignFacilityRole($organization, $owner, 'facility_owner');
    $other = Facility::factory()->create(['organization_id' => $organization->id]);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id, 'user_id' => $admin->id,
        'facility_id' => $facility->id, 'role' => 'facility_admin',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $permissions = app(FacilityPermissions::class);
    expect($permissions->allows($owner, $facility, FacilityPermissions::MANAGE_ALERTS))->toBeTrue()
        ->and($permissions->allows($owner, $other, FacilityPermissions::VIEW_CLIENTS))->toBeFalse()
        ->and($permissions->allows($admin, $facility, FacilityPermissions::MANAGE_CLIENTS))->toBeTrue()
        ->and($permissions->allows($admin, $facility, FacilityPermissions::MANAGE_STAFF_PERMISSIONS))->toBeTrue()
        ->and($permissions->allows($admin, $facility, FacilityPermissions::MANAGE_ALERTS))->toBeFalse()
        ->and($permissions->allows($admin, $facility, FacilityPermissions::VIEW_SHIFT_NOTES))->toBeFalse();
});

it('gives organization admins logistics authority across facilities without clinical access', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $organization->users()->attach($admin, ['role' => 'admin', 'status' => 'active']);
    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    $permissions = app(FacilityPermissions::class);
    expect($permissions->allows($admin, $facility, FacilityPermissions::VIEW_CLIENTS))->toBeTrue()
        ->and($permissions->allows($admin, $facility, FacilityPermissions::MANAGE_CLIENTS))->toBeTrue()
        ->and($permissions->allows($admin, $facility, FacilityPermissions::MANAGE_ALERTS))->toBeFalse()
        ->and($permissions->allows($admin, $facility, FacilityPermissions::VIEW_SHIFT_NOTES))->toBeFalse();
});
