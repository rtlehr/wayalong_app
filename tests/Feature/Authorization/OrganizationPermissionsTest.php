<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('grants owner and admin their organization administration permissions', function (string $role) {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => $role]);
    $permissions = app(OrganizationPermissions::class);

    foreach ([OrganizationPermissions::MANAGE_ORGANIZATION, OrganizationPermissions::MANAGE_FACILITIES, OrganizationPermissions::VIEW_MEMBERS, OrganizationPermissions::MANAGE_MEMBERS] as $permission) {
        expect($permissions->allows($user, $organization, $permission))->toBeTrue();
    }
})->with(['owner', 'admin']);

it('does not grant administrative permissions to clinical or ordinary staff', function (string $role) {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => $role]);
    expect(app(OrganizationPermissions::class)->allows($user, $organization, OrganizationPermissions::MANAGE_FACILITIES))->toBeFalse();
})->with(['clinician', 'staff']);

it('does not allow permissions to leak from a second organization', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user->organizations()->attach($first, ['role' => 'owner']);
    $user->organizations()->attach($second, ['role' => 'staff']);
    $permissions = app(OrganizationPermissions::class);
    expect($permissions->allows($user, $first, OrganizationPermissions::MANAGE_ORGANIZATION))->toBeTrue()
        ->and($permissions->allows($user, $second, OrganizationPermissions::MANAGE_ORGANIZATION))->toBeFalse();
});

it('revokes permission as soon as a membership is deactivated or downgraded', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $user->organizations()->attach($organization, ['role' => 'admin']);
    $permissions = app(OrganizationPermissions::class);
    expect($permissions->allows($user, $organization, OrganizationPermissions::MANAGE_FACILITIES))->toBeTrue();
    $user->organizations()->updateExistingPivot($organization->id, ['role' => 'staff']);
    expect($permissions->allows($user, $organization, OrganizationPermissions::MANAGE_FACILITIES))->toBeFalse();
    $user->organizations()->updateExistingPivot($organization->id, ['role' => 'admin', 'status' => 'inactive']);
    expect($permissions->allows($user, $organization, OrganizationPermissions::MANAGE_FACILITIES))->toBeFalse();
});

it('rejects inactive organizations, unknown permissions, and nonmembers', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $permissions = app(OrganizationPermissions::class);
    expect($permissions->allows($user, $organization, OrganizationPermissions::MANAGE_FACILITIES))->toBeFalse();
    $user->organizations()->attach($organization, ['role' => 'owner']);
    expect($permissions->allows($user, $organization, 'made.up.permission'))->toBeFalse()
        ->and($permissions->allows(null, $organization, OrganizationPermissions::MANAGE_FACILITIES))->toBeFalse();
    $organization->update(['status' => 'inactive']);
    expect($permissions->allows($user, $organization->fresh(), OrganizationPermissions::MANAGE_FACILITIES))->toBeFalse();
});

it('enforces permissions at the existing organization administration routes', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $user->organizations()->attach($organization, ['role' => 'admin']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $this->get(route('organization.admin.index'))->assertOk();
    $user->organizations()->updateExistingPivot($organization->id, ['role' => 'staff']);
    $this->get(route('organization.admin.index'))->assertForbidden();
});
