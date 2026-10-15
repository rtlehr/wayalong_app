<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function memberOf(User $user, Organization $organization, string $membershipStatus = 'active'): void
{
    $user->organizations()->attach($organization, ['status' => $membershipStatus]);
}

it('selects the first active organization by default', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    memberOf($user, $first);
    memberOf($user, $second);

    $this->actingAs($user)->get('/dashboard')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('organizationContext.active.id', $first->id)
            ->has('organizationContext.available', 2));

    expect(session(OrganizationContext::SESSION_KEY))->toBe($first->id);
});

it('switches organization and persists the selection', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    memberOf($user, $first);
    memberOf($user, $second);

    $this->actingAs($user)->post(route('organizations.switch'), ['organization_id' => $second->id])
        ->assertRedirect(route('dashboard'));
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->where('organizationContext.active.id', $second->id));
});

it('rejects switching to an unrelated organization', function () {
    $user = User::factory()->create();
    $allowed = Organization::factory()->create();
    $forbidden = Organization::factory()->create();
    memberOf($user, $allowed);

    $this->actingAs($user)->post(route('organizations.switch'), ['organization_id' => $forbidden->id])
        ->assertForbidden();
    expect(session(OrganizationContext::SESSION_KEY))->toBeNull();
});

it('rejects inactive memberships and inactive organizations', function () {
    $user = User::factory()->create();
    $inactiveMembership = Organization::factory()->create();
    $inactiveOrganization = Organization::factory()->create(['status' => 'inactive']);
    memberOf($user, $inactiveMembership, 'inactive');
    memberOf($user, $inactiveOrganization);

    $this->actingAs($user)->post(route('organizations.switch'), ['organization_id' => $inactiveMembership->id])
        ->assertForbidden();
    $this->post(route('organizations.switch'), ['organization_id' => $inactiveOrganization->id])
        ->assertForbidden();
});

it('recovers when a previously selected membership becomes inactive', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    memberOf($user, $first);
    memberOf($user, $second);

    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $second->id]);
    $user->organizations()->updateExistingPivot($second->id, ['status' => 'inactive']);

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->where('organizationContext.active.id', $first->id)
        ->has('organizationContext.available', 1));
});

it('returns no active organization for a user without memberships', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->where('organizationContext.active', null)
            ->has('organizationContext.available', 0));
});

it('requires authentication and validates switch input', function () {
    $this->post(route('organizations.switch'), ['organization_id' => 1])->assertRedirect();
    $this->actingAs(User::factory()->create())
        ->post(route('organizations.switch'), ['organization_id' => 'invalid'])
        ->assertSessionHasErrors('organization_id');
});
