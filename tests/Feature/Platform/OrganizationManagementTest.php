<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->withoutVite());

function platformLogin(bool $platformAdmin): User
{
    $user = User::factory()->create();
    $user->forceFill(['is_platform_admin' => $platformAdmin])->save();
    test()->actingAs($user);
    return $user;
}

it('restricts platform organization management to platform administrators', function () {
    $this->get(route('platform.organizations.index'))->assertRedirect();
    $organization = Organization::factory()->create();
    $owner = platformLogin(false);
    $owner->organizations()->attach($organization, ['role' => 'owner', 'status' => 'active']);
    $this->get(route('platform.organizations.index'))->assertForbidden();
    $this->post(route('platform.organizations.store'), ['name' => 'Not allowed', 'slug' => 'not-allowed', 'status' => 'active', 'timezone' => 'UTC'])->assertForbidden();
    $this->delete(route('platform.organizations.destroy', $organization))->assertForbidden();
});

it('allows platform administrators to create and edit independent organizations', function () {
    platformLogin(true);
    $this->get(route('platform.organizations.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('platform/Organizations'));
    $this->get(route('platform.organizations.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('platform/OrganizationForm'));
    $this->post(route('platform.organizations.store'), ['name' => 'New Organization', 'slug' => 'new-organization', 'status' => 'active', 'timezone' => 'America/New_York'])->assertRedirect();
    $org = Organization::query()->where('slug', 'new-organization')->firstOrFail();
    $this->patch(route('platform.organizations.update', $org), ['name' => 'Updated Organization', 'slug' => 'new-organization', 'status' => 'inactive', 'timezone' => 'UTC'])->assertRedirect();
    expect($org->fresh()->name)->toBe('Updated Organization');
    expect($org->fresh()->status)->toBe('inactive');
    $this->post(route('platform.organizations.store'), ['name' => 'Duplicate', 'slug' => 'new-organization', 'status' => 'active', 'timezone' => 'UTC'])->assertSessionHasErrors('slug');
});

it('blocks deletion when facilities or users remain, but deletes empty organizations', function () {
    $platformAdmin = platformLogin(true);
    $organization = Organization::factory()->create();

    // Facility records must be created and removed by a member of their organization.
    // Platform administration does not bypass tenant-level model guards.
    $owner = User::factory()->create();
    $owner->organizations()->attach($organization, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($platformAdmin);
    $this->delete(route('platform.organizations.destroy', $organization))->assertSessionHasErrors('organization');
    expect($organization->fresh())->not->toBeNull();

    $this->actingAs($owner);
    $facility->delete();
    $this->actingAs($platformAdmin);
    $member = User::factory()->create();
    $organization->users()->attach($member, ['role' => 'staff', 'status' => 'active']);
    $this->delete(route('platform.organizations.destroy', $organization))->assertSessionHasErrors('organization');
    $organization->users()->detach([$member->id, $owner->id]);
    $this->delete(route('platform.organizations.destroy', $organization))->assertRedirect(route('platform.organizations.index'));
    expect($organization->fresh())->toBeNull();
});
