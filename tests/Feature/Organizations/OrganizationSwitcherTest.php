<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares active organization and only selectable organizations with the sidebar', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create(['name' => 'Alpha Center']);
    $second = Organization::factory()->create(['name' => 'Beta Center']);
    $inactive = Organization::factory()->create(['status' => 'inactive']);
    $disabled = Organization::factory()->create();
    $outsider = Organization::factory()->create();
    $user->organizations()->attach($first, ['role' => 'clinician']);
    $user->organizations()->attach($second, ['role' => 'staff']);
    $user->organizations()->attach($inactive);
    $user->organizations()->attach($disabled, ['status' => 'inactive']);

    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('organizationContext.active.id', $first->id)
        ->has('organizationContext.available', 2)
        ->where('organizationContext.available.0.name', 'Alpha Center')
        ->where('organizationContext.available.0.role', 'clinician')
        ->where('organizationContext.available.1.id', $second->id));
});

it('updates the selected organization in the shared sidebar context', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user->organizations()->attach([$first->id, $second->id]);

    $this->actingAs($user)->post(route('organizations.switch'), ['organization_id' => $second->id])
        ->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('organizationContext.active.id', $second->id)
        ->has('organizationContext.available', 2));
});

it('does not replace the selected organization when a forged switch is rejected', function () {
    $user = User::factory()->create();
    $allowed = Organization::factory()->create();
    $other = Organization::factory()->create();
    $user->organizations()->attach($allowed);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $allowed->id]);

    $this->post(route('organizations.switch'), ['organization_id' => $other->id])->assertForbidden();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('organizationContext.active.id', $allowed->id)
        ->has('organizationContext.available', 1));
});

it('drops a formerly selectable organization after the membership is disabled', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user->organizations()->attach([$first->id, $second->id]);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $second->id]);
    $user->organizations()->updateExistingPivot($second->id, ['status' => 'inactive']);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('organizationContext.active.id', $first->id)
        ->has('organizationContext.available', 1));
});

it('shares an empty selector for users with no memberships', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('organizationContext.active', null)
            ->has('organizationContext.available', 0));
});
