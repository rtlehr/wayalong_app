<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function staffPageActor(string $role = 'owner'): array
{
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $actor->organizations()->attach($organization, ['role' => $role, 'status' => 'active']);
    test()->actingAs($actor)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    return [$organization, $actor];
}

it('opens standalone add, view and edit staff pages', function () {
    [$org] = staffPageActor();
    $member = User::factory()->create();
    $member->organizations()->attach($org, ['role' => 'staff', 'status' => 'active']);
    $this->get(route('organization.admin.staff.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('organizations/StaffForm')->where('mode', 'create'));
    $this->get(route('organization.admin.staff.show', $member->id))->assertOk()->assertInertia(fn (Assert $page) => $page->component('organizations/StaffForm')->where('mode', 'view')->where('member.id', $member->id));
    $this->get(route('organization.admin.staff.edit', $member->id))->assertOk()->assertInertia(fn (Assert $page) => $page->component('organizations/StaffForm')->where('mode', 'edit'));
});

it('removes a membership without deleting the user account', function () {
    [$org] = staffPageActor();
    $member = User::factory()->create();
    $member->organizations()->attach($org, ['role' => 'staff', 'status' => 'active']);
    $this->delete(route('organization.admin.staff.destroy', $member->id))->assertRedirect(route('organization.admin.staff.index'));
    $this->assertDatabaseHas('users', ['id' => $member->id]);
    $this->assertDatabaseMissing('organization_user', ['organization_id' => $org->id, 'user_id' => $member->id]);
});

it('blocks removing the final active owner', function () {
    [, $owner] = staffPageActor();
    $this->delete(route('organization.admin.staff.destroy', $owner->id))->assertSessionHasErrors('role');
});

it('prevents regular staff from accessing staff forms or deleting memberships', function () {
    [$org] = staffPageActor('staff');
    $other = User::factory()->create();
    $other->organizations()->attach($org, ['role' => 'staff', 'status' => 'active']);
    $this->get(route('organization.admin.staff.create'))->assertForbidden();
    $this->get(route('organization.admin.staff.show', $other->id))->assertForbidden();
    $this->get(route('organization.admin.staff.edit', $other->id))->assertForbidden();
    $this->delete(route('organization.admin.staff.destroy', $other->id))->assertForbidden();
});
