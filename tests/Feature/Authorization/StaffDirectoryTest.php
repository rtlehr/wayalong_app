<?php

use App\Models\Organization;
use App\Models\Facility;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

function staffDirectoryAs(string $role = 'owner'): Organization
{
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $actor->organizations()->attach($organization, ['role' => $role, 'status' => 'active']);
    test()->actingAs($actor)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);

    return $organization;
}

function assignStaffToFacility(Organization $organization, array $users): Facility
{
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    foreach ($users as $user) {
        DB::table('organization_staff_facilities')->insert([
            'organization_id' => $organization->id,
            'facility_id' => $facility->id,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    return $facility;
}


it('limits the staff directory to the selected organization', function () {
    $organization = staffDirectoryAs();
    $member = User::factory()->create(['name' => 'Taylor Member']);
    $organization->users()->attach($member, ['role' => 'clinician']);
    assignStaffToFacility($organization, [$organization->users()->wherePivot('role', 'owner')->firstOrFail(), $member]);
    $foreignOrganization = Organization::factory()->create();
    $foreign = User::factory()->create(['name' => 'External Member']);
    $foreignOrganization->users()->attach($foreign);

    $this->get(route('organization.admin.staff.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('organizations/Staff')
            ->where('members.total', 2)
            ->has('members.data', 2)
            ->missing('members.data.2')
            ->etc());
});

it('searches the current organization by name or email', function () {
    $organization = staffDirectoryAs();
    $one = User::factory()->create(['name' => 'Unique Person', 'email' => 'unique@example.test']);
    $two = User::factory()->create(['name' => 'Another Person', 'email' => 'another@example.test']);
    $organization->users()->attach([$one->id, $two->id]);
    assignStaffToFacility($organization, [$one, $two]);

    $this->get(route('organization.admin.staff.index', ['search' => 'unique@example.test']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 1)
            ->where('members.data.0.id', $one->id)
            ->etc());
});

it('filters memberships by status and role together', function () {
    $organization = staffDirectoryAs();
    $active = User::factory()->create();
    $inactive = User::factory()->create();
    $organization->users()->attach($active, ['role' => 'clinician', 'status' => 'active']);
    $organization->users()->attach($inactive, ['role' => 'clinician', 'status' => 'inactive']);
    assignStaffToFacility($organization, [$active, $inactive]);

    $this->get(route('organization.admin.staff.index', ['role' => 'clinician', 'status' => 'inactive']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 1)
            ->where('members.data.0.id', $inactive->id)
            ->etc());
});

it('paginates staff without leaking outside memberships', function () {
    $organization = staffDirectoryAs();
    $members = User::factory()->count(18)->create();
    foreach ($members as $member) {
        $organization->users()->attach($member);
    }

    assignStaffToFacility($organization, array_merge([$organization->users()->wherePivot('role', 'owner')->firstOrFail()], $members->all()));

    $this->get(route('organization.admin.staff.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 19)
            ->has('members.data', 15)
            ->etc());
    $this->get(route('organization.admin.staff.index', ['page' => 2]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('members.total', 19)
            ->has('members.data', 4)
            ->etc());
});

it('rejects non-admin access and invalid filters', function () {
    staffDirectoryAs('staff');
    $this->get(route('organization.admin.staff.index'))->assertForbidden();

    staffDirectoryAs('owner');
    $this->get(route('organization.admin.staff.index', ['status' => 'unknown']))->assertSessionHasErrors('status');
    $this->get(route('organization.admin.staff.index', ['role' => 'superuser']))->assertSessionHasErrors('role');
});
