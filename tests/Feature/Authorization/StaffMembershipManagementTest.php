<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function staffManagementSetup(string $role = 'owner'): array
{
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $actor->organizations()->attach($organization, ['role' => $role, 'status' => 'active']);
    test()->actingAs($actor)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);

    return [$actor, $organization];
}

it('allows an owner to add an existing user', function () {
    [, $organization] = staffManagementSetup();
    $member = User::factory()->create();
    $this->post(route('organization.admin.members.store'), ['email' => $member->email, 'role' => 'clinician'])->assertRedirect();
    expect($organization->users()->whereKey($member->id)->first()->pivot->role)->toBe('clinician');
});

it('prevents duplicate memberships and unknown users', function () {
    [, $organization] = staffManagementSetup();
    $member = User::factory()->create();
    $organization->users()->attach($member, ['role' => 'staff']);
    $this->post(route('organization.admin.members.store'), ['email' => $member->email, 'role' => 'staff'])->assertSessionHasErrors('email');
    $this->post(route('organization.admin.members.store'), ['email' => 'missing@example.com', 'role' => 'staff'])->assertSessionHasErrors('email');
});

it('lets admins update ordinary staff membership', function () {
    [, $organization] = staffManagementSetup('admin');
    $member = User::factory()->create();
    $organization->users()->attach($member, ['role' => 'staff']);
    $this->patch(route('organization.admin.members.update', $member), ['role' => 'clinician', 'status' => 'inactive'])->assertRedirect();
    $pivot = $organization->users()->whereKey($member->id)->first()->pivot;
    expect($pivot->role)->toBe('clinician')->and($pivot->status)->toBe('inactive');
});

it('rejects member management by clinicians and staff', function (string $role) {
    [, $organization] = staffManagementSetup($role);
    $member = User::factory()->create();
    $organization->users()->attach($member);
    $this->post(route('organization.admin.members.store'), ['email' => User::factory()->create()->email, 'role' => 'staff'])->assertForbidden();
    $this->patch(route('organization.admin.members.update', $member), ['role' => 'admin', 'status' => 'active'])->assertForbidden();
})->with(['staff', 'clinician']);

it('does not let admins promote anyone to owner', function () {
    [, $organization] = staffManagementSetup('admin');
    $member = User::factory()->create();
    $organization->users()->attach($member);
    $this->patch(route('organization.admin.members.update', $member), ['role' => 'owner', 'status' => 'active'])->assertForbidden();
    $this->post(route('organization.admin.members.store'), ['email' => User::factory()->create()->email, 'role' => 'owner'])->assertForbidden();
});

it('does not let admins change an owner membership', function () {
    [, $organization] = staffManagementSetup('admin');
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner']);
    $this->patch(route('organization.admin.members.update', $owner), ['role' => 'staff', 'status' => 'inactive'])->assertForbidden();
});

it('prevents deactivating the last active owner', function () {
    [$owner, $organization] = staffManagementSetup();
    $this->patch(route('organization.admin.members.update', $owner), ['role' => 'staff', 'status' => 'inactive'])->assertSessionHasErrors('role');
    expect($organization->users()->whereKey($owner->id)->first()->pivot->status)->toBe('active');
});

it('allows owner transfer when another active owner remains', function () {
    [$owner, $organization] = staffManagementSetup();
    $second = User::factory()->create();
    $organization->users()->attach($second, ['role' => 'owner']);
    $this->patch(route('organization.admin.members.update', $owner), ['role' => 'staff', 'status' => 'inactive'])->assertRedirect();
    expect($organization->users()->wherePivot('role', 'owner')->wherePivot('status', 'active')->count())->toBe(1);
});

it('does not allow editing a user outside the active organization', function () {
    staffManagementSetup();
    $outside = Organization::factory()->create();
    $member = User::factory()->create();
    $outside->users()->attach($member);
    $this->patch(route('organization.admin.members.update', $member), ['role' => 'admin', 'status' => 'active'])->assertNotFound();
});

it('rejects invalid membership role and status values', function () {
    [, $organization] = staffManagementSetup();
    $member = User::factory()->create();
    $organization->users()->attach($member);
    $this->patch(route('organization.admin.members.update', $member), ['role' => 'superuser', 'status' => 'unknown'])->assertSessionHasErrors(['role', 'status']);
});


it('reports invalid role and status independently without changing membership', function () {
    [, $organization] = staffManagementSetup();
    $member = User::factory()->create();
    $organization->users()->attach($member, ['role' => 'staff', 'status' => 'active']);

    $this->patch(route('organization.admin.members.update', $member), [
        'role' => 'superuser', 'status' => 'active',
    ])->assertSessionHasErrors('role');

    $this->patch(route('organization.admin.members.update', $member), [
        'role' => 'staff', 'status' => 'unknown',
    ])->assertSessionHasErrors('status');

    $membership = $organization->users()->whereKey($member->id)->firstOrFail()->pivot;
    expect($membership->role)->toBe('staff')->and($membership->status)->toBe('active');
});
