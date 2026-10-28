<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function customRoleAdmin(Organization $organization): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => 'owner']);
    return $user;
}

it('creates a role and assigns it to a member with scoped permissions', function () {
    $org = Organization::factory()->create();
    $owner = customRoleAdmin($org);
    $staff = User::factory()->create();
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $this->post(route('organization.admin.roles.store'), ['name' => 'Site coordinator', 'permissions' => [OrganizationPermissions::MANAGE_FACILITIES]])->assertRedirect();
    $role = $org->customRoles()->firstOrFail();
    $this->post(route('organization.admin.members.store'), ['email' => $staff->email, 'role' => 'custom:'.$role->id])->assertRedirect();
    expect(app(OrganizationPermissions::class)->allows($staff, $org, OrganizationPermissions::MANAGE_FACILITIES))->toBeTrue()
        ->and(app(OrganizationPermissions::class)->allows($staff, $org, OrganizationPermissions::MANAGE_MEMBERS))->toBeFalse();
});

it('never permits privilege escalation through custom role permissions', function () {
    $org = Organization::factory()->create();
    $this->actingAs(customRoleAdmin($org))->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $this->post(route('organization.admin.roles.store'), ['name' => 'Unsafe', 'permissions' => [OrganizationPermissions::MANAGE_MEMBERS]])->assertSessionHasErrors('permissions.0');
    expect($org->customRoles()->count())->toBe(0);
});

it('rejects foreign organization roles for new memberships', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $role = $second->customRoles()->create(['name' => 'Foreign role', 'permissions' => [OrganizationPermissions::MANAGE_FACILITIES]]);
    $staff = User::factory()->create();
    $this->actingAs(customRoleAdmin($first))->withSession([OrganizationContext::SESSION_KEY => $first->id]);
    $this->post(route('organization.admin.members.store'), ['email' => $staff->email, 'role' => 'custom:'.$role->id])->assertSessionHasErrors('role');
    expect($first->users()->whereKey($staff->id)->exists())->toBeFalse();
});

it('updates role permissions and revokes access immediately', function () {
    $org = Organization::factory()->create();
    $owner = customRoleAdmin($org);
    $role = $org->customRoles()->create(['name' => 'Coordinator', 'permissions' => [OrganizationPermissions::MANAGE_FACILITIES]]);
    $staff = User::factory()->create();
    $staff->organizations()->attach($org, ['role' => 'custom:'.$role->id]);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $this->patch(route('organization.admin.roles.update', $role->id), ['name' => 'Coordinator', 'permissions' => []])->assertRedirect()->assertSessionHasNoErrors();
    expect($role->fresh()->permissions)->toBe([]);
    expect(app(OrganizationPermissions::class)->allows($staff, $org, OrganizationPermissions::MANAGE_FACILITIES))->toBeFalse();
});

it('blocks deleting assigned roles and foreign organization role updates', function () {
    $org = Organization::factory()->create();
    $other = Organization::factory()->create();
    $owner = customRoleAdmin($org);
    $role = $org->customRoles()->create(['name' => 'Assigned', 'permissions' => []]);
    $foreign = $other->customRoles()->create(['name' => 'Foreign', 'permissions' => []]);
    $staff = User::factory()->create();
    $staff->organizations()->attach($org, ['role' => 'custom:'.$role->id]);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $this->delete(route('organization.admin.roles.destroy', $role->id))->assertSessionHasErrors('role');
    $this->patch(route('organization.admin.roles.update', $foreign->id), ['name' => 'Hijacked', 'permissions' => []])->assertNotFound();
});

it('denies custom role management to ordinary staff', function () {
    $org = Organization::factory()->create();
    $staff = User::factory()->create();
    $staff->organizations()->attach($org, ['role' => 'staff']);
    $this->actingAs($staff)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $this->post(route('organization.admin.roles.store'), ['name' => 'Unauthorized', 'permissions' => []])->assertForbidden();
});

it('applies a granular facility permission from a custom organization role only at an assigned facility', function () {
    $org = Organization::factory()->create();
    $owner = customRoleAdmin($org);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id]);

    $this->post(route('organization.admin.roles.store'), [
        'name' => 'Shift handoff reviewer',
        'permissions' => [\App\Support\FacilityPermissions::VIEW_SHIFT_NOTES, \App\Support\FacilityPermissions::ACK_SHIFT_NOTES],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $role = $org->customRoles()->firstOrFail();
    $staff = User::factory()->create();
    $staff->organizations()->attach($org, ['role' => 'custom:'.$role->id, 'status' => 'active']);
    $assigned = \App\Models\Facility::factory()->create(['organization_id' => $org->id]);
    $unassigned = \App\Models\Facility::factory()->create(['organization_id' => $org->id]);
    \Illuminate\Support\Facades\DB::table('organization_staff_facilities')->insert([
        'organization_id' => $org->id, 'facility_id' => $assigned->id, 'user_id' => $staff->id,
        'role' => 'custom:'.$role->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $permissions = app(\App\Support\FacilityPermissions::class);

    expect($permissions->allows($staff, $assigned, \App\Support\FacilityPermissions::VIEW_SHIFT_NOTES))->toBeTrue()
        ->and($permissions->allows($staff, $assigned, \App\Support\FacilityPermissions::ACK_SHIFT_NOTES))->toBeTrue()
        ->and($permissions->allows($staff, $assigned, \App\Support\FacilityPermissions::WRITE_SHIFT_NOTES))->toBeFalse()
        ->and($permissions->allows($staff, $assigned, \App\Support\FacilityPermissions::MANAGE_CLIENTS))->toBeFalse()
        ->and($permissions->allows($staff, $unassigned, \App\Support\FacilityPermissions::VIEW_SHIFT_NOTES))->toBeFalse();

    $this->patch(route('organization.admin.roles.update', $role->id), [
        'name' => 'Shift handoff reviewer', 'permissions' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($permissions->allows($staff, $assigned, \App\Support\FacilityPermissions::VIEW_SHIFT_NOTES))->toBeFalse();
});

it('does not allow a custom role to grant full organization administration', function () {
    $org = Organization::factory()->create();
    $this->actingAs(customRoleAdmin($org))->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $this->post(route('organization.admin.roles.store'), [
        'name' => 'Escalation attempt',
        'permissions' => [OrganizationPermissions::MANAGE_ORGANIZATION],
    ])->assertSessionHasErrors('permissions.0');
    expect($org->customRoles()->count())->toBe(0);
});

it('exposes only the supported granular permission keys and valid templates', function () {
    $supported = OrganizationPermissions::CUSTOM_ROLE_PERMISSIONS;
    foreach (OrganizationPermissions::permissionGroups() as $group) {
        foreach ($group['permissions'] as $permission) {
            expect($permission['key'])->toBeIn($supported);
        }
    }
    foreach (OrganizationPermissions::roleTemplates() as $template) {
        foreach ($template['permissions'] as $permission) {
            expect($permission)->toBeIn($supported);
        }
    }
});
