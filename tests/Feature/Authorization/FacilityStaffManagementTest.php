<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use App\Support\FacilityContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void { $this->withoutVite(); });

function managedRosterAssignment(Organization $organization, Facility $facility, User $user, string $role): void
{
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id, 'facility_id' => $facility->id,
        'user_id' => $user->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('allows a facility admin to add and remove existing organization members without deleting their identities', function (): void {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $staff = User::factory()->create();
    $organization->users()->attach($admin, ['role' => 'staff', 'status' => 'active']);
    $organization->users()->attach($staff, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    managedRosterAssignment($organization, $facility, $admin, 'facility_admin');
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id]);
    Log::spy();
    $this->post(route('facility.staff.assignments.store'), ['user_id' => $staff->id, 'role' => 'staff'])->assertRedirect();
    Log::shouldHaveReceived('info')->with('WayAlong facility staff assignment created', \Mockery::on(fn (array $context) =>
        $context['actor_id'] === $admin->id && $context['subject_id'] === $staff->id
        && $context['organization_id'] === $organization->id && $context['facility_id'] === $facility->id
        && $context['role'] === 'staff'
    ))->once();
    expect(DB::table('organization_staff_facilities')->where('facility_id', $facility->id)->where('user_id', $staff->id)->exists())->toBeTrue();
    $this->delete(route('facility.staff.assignments.destroy', $staff->id))->assertRedirect();
    Log::shouldHaveReceived('info')->with('WayAlong facility staff assignment removed', \Mockery::on(fn (array $context) =>
        $context['actor_id'] === $admin->id && $context['subject_id'] === $staff->id
        && $context['organization_id'] === $organization->id && $context['facility_id'] === $facility->id
        && $context['role'] === 'staff'
    ))->once();
    expect(DB::table('organization_staff_facilities')->where('facility_id', $facility->id)->where('user_id', $staff->id)->exists())->toBeFalse()
        ->and(User::find($staff->id))->not->toBeNull()
        ->and($organization->users()->whereKey($staff->id)->exists())->toBeTrue();
});

it('blocks facility admins from appointing owners or removing the final facility owner', function (): void {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    foreach ([$admin, $owner, $staff] as $user) {
        $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    }
    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    managedRosterAssignment($organization, $facility, $admin, 'facility_admin');
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id]);
    managedRosterAssignment($organization, $facility, $owner, 'facility_owner');
    $this->post(route('facility.staff.assignments.store'), ['user_id' => $staff->id, 'role' => 'facility_owner'])->assertForbidden();
    $this->delete(route('facility.staff.assignments.destroy', $owner->id))->assertForbidden();
    expect(DB::table('organization_staff_facilities')->where('facility_id', $facility->id)->where('user_id', $owner->id)->exists())->toBeTrue();
});

it('rejects inactive memberships and cross-organization facility assignments', function (): void {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    $admin = User::factory()->create();
    $inactive = User::factory()->create();
    $outsider = User::factory()->create();
    $organization->users()->attach($admin, ['role' => 'staff', 'status' => 'active']);
    $organization->users()->attach($inactive, ['role' => 'staff', 'status' => 'inactive']);
    $other->users()->attach($outsider, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    managedRosterAssignment($organization, $facility, $admin, 'facility_admin');
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id]);
    $this->post(route('facility.staff.assignments.store'), ['user_id' => $inactive->id, 'role' => 'staff'])->assertSessionHasErrors('user_id');
    $this->post(route('facility.staff.assignments.store'), ['user_id' => $outsider->id, 'role' => 'staff'])->assertSessionHasErrors('user_id');
});

it('marks the sole active facility owner as protected in the roster and keeps the server-side removal guard', function (): void {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $owner = User::factory()->create();
    foreach ([$admin, $owner] as $user) {
        $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    }
    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    managedRosterAssignment($organization, $facility, $admin, 'facility_admin');
    managedRosterAssignment($organization, $facility, $owner, 'facility_owner');
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id]);

    $this->get(route('facility.staff.index'))->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
        ->component('facilities/StaffRoster')
        ->where('staff', fn ($staff) => collect($staff)->firstWhere('id', $owner->id)['isLastFacilityOwner'] === true
            && collect($staff)->firstWhere('id', $owner->id)['canRemove'] === false
            && collect($staff)->firstWhere('id', $admin->id)['isLastFacilityOwner'] === false)
        ->etc());
    $this->delete(route('facility.staff.assignments.destroy', $owner->id))->assertForbidden();
});


it('warns an authorized organization owner rather than removing the sole facility owner', function (): void {
    $organization = Organization::factory()->create();
    $organizationOwner = User::factory()->create();
    $facilityOwner = User::factory()->create();
    $organization->users()->attach($organizationOwner, ['role' => 'owner', 'status' => 'active']);
    $organization->users()->attach($facilityOwner, ['role' => 'staff', 'status' => 'active']);

    $this->actingAs($organizationOwner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    managedRosterAssignment($organization, $facility, $facilityOwner, 'facility_owner');
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id]);

    $this->delete(route('facility.staff.assignments.destroy', $facilityOwner->id))
        ->assertSessionHasErrors('role');

    expect(DB::table('organization_staff_facilities')
        ->where('facility_id', $facility->id)->where('user_id', $facilityOwner->id)->exists())->toBeTrue();
});
