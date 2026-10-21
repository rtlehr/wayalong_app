<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses a single central user and password across two authorized organizations', function () {
    $first = Organization::factory()->create(['status' => 'active']);
    $second = Organization::factory()->create(['status' => 'active']);
    $user = User::factory()->create(['organization_id' => $first->id, 'password' => 'password']);
    $first->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $second->users()->attach($user, ['role' => 'admin', 'status' => 'active']);

    $this->get(route('organizations.login', $first->slug))->assertOk();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
    expect(session(OrganizationContext::SESSION_KEY))->toBe($first->id);

    $this->post(route('organizations.switch'), ['organization_id' => $second->id])
        ->assertRedirect(route('dashboard'));
    expect(session(OrganizationContext::SESSION_KEY))->toBe($second->id);
    expect(User::where('email', $user->email)->count())->toBe(1);
});

it('refuses switching or logging into an inactive membership of a shared user', function () {
    $first = Organization::factory()->create(['status' => 'active']);
    $second = Organization::factory()->create(['status' => 'active']);
    $user = User::factory()->create(['organization_id' => $first->id, 'password' => 'password']);
    $first->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $second->users()->attach($user, ['role' => 'staff', 'status' => 'inactive']);

    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $first->id]);
    $this->post(route('organizations.switch'), ['organization_id' => $second->id])->assertForbidden();
    expect(session(OrganizationContext::SESSION_KEY))->toBe($first->id);

    $this->post(route('logout'));
    $this->get(route('organizations.login', $second->slug))->assertOk();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertGuest();
});
