<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\AdminToolCatalog;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('opens settings, facilities, roles and staff management as separate administration pages', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $owner->organizations()->attach($organization, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);

    $pages = [
        'organization.admin.index' => 'organizations/Admin',
        'organization.admin.facilities.index' => 'organizations/Facilities',
        'organization.admin.roles.index' => 'organizations/Roles',
        'organization.admin.members.index' => 'organizations/Members',
        'organization.admin.staff.index' => 'organizations/Staff',
    ];

    foreach ($pages as $route => $component) {
        $this->get(route($route))->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
    }

    $links = collect(app(AdminToolCatalog::class)->forUser($owner, $organization))->pluck('href', 'key');
    expect($links->get('settings'))->toBe('/organization/admin');
    expect($links->get('facilities'))->toBe('/organization/admin/facilities');
    expect($links->get('roles'))->toBe('/organization/admin/roles');
    expect($links->get('staff'))->toBe('/organization/admin/staff');
});

it('protects the new settings, facilities, and roles pages from ordinary staff', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->create();
    $member->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($member)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);

    foreach (['organization.admin.index', 'organization.admin.facilities.index', 'organization.admin.roles.index', 'organization.admin.members.index'] as $route) {
        $this->get(route($route))->assertForbidden();
    }
});
