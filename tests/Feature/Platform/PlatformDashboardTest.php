<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->withoutVite());

it('shows a dedicated dashboard with platform-wide organization statistics', function () {
    $platformAdmin = User::factory()->create();
    $platformAdmin->forceFill(['is_platform_admin' => true])->save();
    $active = Organization::factory()->create(['status' => 'active']);
    Organization::factory()->create(['status' => 'inactive']);

    $owner = User::factory()->create();
    $owner->organizations()->attach($active, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner);
    Facility::factory()->create(['organization_id' => $active->id]);

    $this->actingAs($platformAdmin);
    $this->get(route('platform.organizations.index', ['search' => $active->name]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('platform/Organizations')
        ->where('organizations.data.0.facilities_count', 1)
        ->etc());
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('platform/Dashboard')
        ->where('stats.organizations', 2)
        ->where('stats.activeOrganizations', 1)
        ->where('stats.inactiveOrganizations', 1)
        ->where('stats.facilities', 1)
        ->has('organizations', 2)
        ->etc());
});

it('does not expose the platform dashboard to ordinary organization users', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($user)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
    $this->get(route('platform.organizations.index'))->assertForbidden();
});
