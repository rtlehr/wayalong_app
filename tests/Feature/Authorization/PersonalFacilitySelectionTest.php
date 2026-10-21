<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\FacilityContext;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () { $this->withoutVite(); });

function createPersonalFacility(Organization $organization, User $user): Facility
{
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id, 'user_id' => $user->id,
        'facility_id' => $facility->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    return $facility;
}

it('shows only facility assignments belonging to active memberships', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = createPersonalFacility($organization, $user);
    Facility::factory()->create(['organization_id' => $organization->id]);

    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('memberships', 1)
        ->has('memberships.0.facilities', 1)
        ->where('memberships.0.facilities.0.id', $facility->id)
        ->etc());
});

it('permits selecting only a facility assigned to the authenticated member', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $assigned = createPersonalFacility($organization, $user);
    $unassigned = Facility::factory()->create(['organization_id' => $organization->id]);

    $this->post(route('facilities.switch'), ['facility_id' => $unassigned->id])->assertForbidden();
    $this->post(route('facilities.switch'), ['facility_id' => $assigned->id])->assertRedirect(route('dashboard'));
    expect(session(FacilityContext::SESSION_KEY))->toBe($assigned->id);
});

it('clears facility selection after switching organizations', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user = User::factory()->create();
    $first->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $second->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $first->id]);
    $facility = createPersonalFacility($first, $user);

    $this->post(route('facilities.switch'), ['facility_id' => $facility->id])->assertRedirect();
    $this->post(route('organizations.switch'), ['organization_id' => $second->id])->assertRedirect();
    expect(session(FacilityContext::SESSION_KEY))->toBeNull();
    $this->post(route('facilities.switch'), ['facility_id' => $facility->id])->assertForbidden();
});

it('rejects a facility when its organization membership is inactive', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = createPersonalFacility($organization, $user);
    $organization->users()->updateExistingPivot($user->id, ['status' => 'inactive']);

    $this->post(route('facilities.switch'), ['facility_id' => $facility->id])->assertForbidden();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->has('memberships', 0)->etc());
});
