<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

it('shows a login page named for the requested active organization', function () {
    $org = Organization::factory()->create(['slug' => 'test-org', 'status' => 'active']);
    $this->get(route('organizations.login', $org->slug))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')->where('organizationName', $org->name)->etc());
    expect(session('login_organization_id'))->toBe($org->id);
});

it('does not let a user authenticate through another organizations login', function () {
    $a = Organization::factory()->create(['status' => 'active']);
    $b = Organization::factory()->create(['status' => 'active']);
    $user = User::factory()->create(['password' => Hash::make('sample-password')]);
    $a->users()->attach($user, ['role' => 'staff', 'status' => 'active']);

    $this->get(route('organizations.login', $b->slug));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'sample-password']);
    $this->assertGuest();

    $this->get(route('organizations.login', $a->slug));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'sample-password'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    expect(session(OrganizationContext::SESSION_KEY))->toBe($a->id);
});

it('denies an inactive organization and inactive organization membership', function () {
    $inactiveOrg = Organization::factory()->create(['status' => 'inactive']);
    $this->get(route('organizations.login', $inactiveOrg->slug))->assertNotFound();

    $org = Organization::factory()->create(['status' => 'active']);
    $user = User::factory()->create();
    $org->users()->attach($user, ['role' => 'staff', 'status' => 'inactive']);
    $this->get(route('organizations.login', $org->slug));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertGuest();
});
