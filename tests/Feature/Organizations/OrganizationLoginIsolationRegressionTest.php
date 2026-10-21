<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows central credentials at the generic login during the transition', function () {
    $organization = Organization::factory()->create(['status' => 'active']);
    $user = User::factory()->create(['organization_id' => $organization->id, 'password' => 'password']);
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
    $this->post(route('logout'));

    $this->get(route('organizations.login', $organization->slug))->assertOk();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

it('retains the generic login for an independent platform administrator', function () {
    $user = User::factory()->create([
        'organization_id' => null,
        'is_platform_admin' => true,
        'password' => 'password',
    ]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

it('allows central identity access to another tenant only with an active membership', function () {
    $first = Organization::factory()->create(['status' => 'active']);
    $second = Organization::factory()->create(['status' => 'active']);
    $user = User::factory()->create(['organization_id' => $first->id, 'password' => 'password']);
    $first->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $second->users()->attach($user, ['role' => 'staff', 'status' => 'active']);

    $this->get(route('organizations.login', $second->slug));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});
