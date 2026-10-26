<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationRole;
use App\Models\User;
use App\Support\AdminToolCatalog;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

function commandCenterLogin(string $role = 'owner'): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => $role, 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);

    return [$user, $organization];
}

it('requires authentication and an administrative permission', function () {
    $this->get(route('admin.command-center'))->assertRedirect();
    commandCenterLogin('staff');
    $this->get(route('admin.command-center'))->assertForbidden();
});

it('displays the selected organization and counts only its records', function () {
    [$owner, $organization] = commandCenterLogin();
    $member = User::factory()->create();
    $organization->users()->attach($member, ['role' => 'staff', 'status' => 'inactive']);
    Facility::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
    Facility::factory()->create(['organization_id' => $organization->id, 'status' => 'inactive']);
    OrganizationRole::create(['organization_id' => $organization->id, 'name' => 'Scheduler', 'permissions' => []]);
    OrganizationInvitation::create([
        'organization_id' => $organization->id,
        'email' => 'pending@example.test', 'role' => 'staff',
        'token_hash' => hash('sha256', 'invitation-sample'),
        'invited_by' => $owner->id, 'expires_at' => now()->addDays(2),
    ]);
    $foreign = Organization::factory()->create();
    \Illuminate\Support\Facades\DB::table('facilities')->insert([
        'organization_id' => $foreign->id, 'name' => 'Foreign Facility', 'code' => 'FOREIGN1',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreign->users()->attach(User::factory()->create());

    $this->get(route('admin.command-center'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/CommandCenter')
        ->where('organization.id', $organization->id)
        ->where('stats.facilities', 2)
        ->where('stats.activeFacilities', 1)
        ->where('stats.staff', 2)
        ->where('stats.activeStaff', 1)
        ->where('stats.customRoles', 1)
        ->where('stats.pendingInvitations', 1)
        ->has('tools', 6)
        ->etc());
});

it('does not leak administrative privileges across organizations', function () {
    [$user, $first] = commandCenterLogin('owner');
    $second = Organization::factory()->create();
    $user->organizations()->attach($second, ['role' => 'staff', 'status' => 'active']);
    $this->withSession([OrganizationContext::SESSION_KEY => $second->id]);
    $this->get(route('admin.command-center'))->assertForbidden();
    $this->withSession([OrganizationContext::SESSION_KEY => $first->id]);
    $this->get(route('admin.command-center'))->assertOk();
});

it('revokes access immediately after an admin is demoted or deactivated', function () {
    [$user, $organization] = commandCenterLogin('admin');
    $this->get(route('admin.command-center'))->assertOk();
    $user->organizations()->updateExistingPivot($organization->id, ['role' => 'staff']);
    $this->get(route('admin.command-center'))->assertForbidden();
    $user->organizations()->updateExistingPivot($organization->id, ['role' => 'admin', 'status' => 'inactive']);
    $this->get(route('admin.command-center'))->assertForbidden();
});

it('filters the tool catalog by the permissions of a custom role', function () {
    [$user, $organization] = commandCenterLogin('staff');
    $role = OrganizationRole::create([
        'organization_id' => $organization->id,
        'name' => 'Facility Coordinator',
        'permissions' => ['facilities.manage'],
    ]);
    $user->organizations()->updateExistingPivot($organization->id, ['role' => 'custom:'.$role->id]);
    $catalog = app(AdminToolCatalog::class)->forUser($user, $organization);
    expect(array_column($catalog, 'key'))->toBe([]);
    $this->get(route('admin.command-center'))->assertForbidden();
});

it('does not admit inactive organizations to the admin dashboard', function () {
    [, $organization] = commandCenterLogin();
    $organization->update(['status' => 'inactive']);
    $this->get(route('admin.command-center'))->assertForbidden();
});
