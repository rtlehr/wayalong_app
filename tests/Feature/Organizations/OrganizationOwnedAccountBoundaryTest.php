<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows a shared identity to switch between active memberships despite a transitional owner field', function () {
    $first = Organization::factory()->create(['status' => 'active']);
    $second = Organization::factory()->create(['status' => 'active']);
    $user = User::factory()->create(['organization_id' => $first->id]);
    $first->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $second->users()->attach($user, ['role' => 'staff', 'status' => 'active']);

    $this->actingAs($user)->post(route('organizations.switch'), ['organization_id' => $second->id])
        ->assertRedirect(route('dashboard'));
    expect(session(OrganizationContext::SESSION_KEY))->toBe($second->id);

});

it('allows one identity to log into either organization when both memberships are active', function () {
    $first = Organization::factory()->create(['status' => 'active']);
    $second = Organization::factory()->create(['status' => 'active']);
    $user = User::factory()->create(['organization_id' => $first->id, 'password' => 'password']);
    $first->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $second->users()->attach($user, ['role' => 'staff', 'status' => 'active']);

    $this->get(route('organizations.login', $second->slug))->assertOk();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
    expect(session(OrganizationContext::SESSION_KEY))->toBe($second->id);

    $this->post(route('logout'));
    $this->get(route('organizations.login', $first->slug))->assertOk();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

it('keeps transitional ownerless accounts on their existing membership flow until migration', function () {
    $organization = Organization::factory()->create(['status' => 'active']);
    $legacyUser = User::factory()->create(['organization_id' => null]);
    $organization->users()->attach($legacyUser, ['role' => 'staff', 'status' => 'active']);

    $this->actingAs($legacyUser)->post(route('organizations.switch'), ['organization_id' => $organization->id])
        ->assertRedirect(route('dashboard'));
});
