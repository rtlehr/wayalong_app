<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\FacilityContext;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('clears a stale facility selection after the only organization membership is deactivated', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create();
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id]);

    $user->organizations()->updateExistingPivot($organization->id, ['status' => 'inactive']);

    $this->get(route('dashboard'))->assertOk();
    expect(session(OrganizationContext::SESSION_KEY))->toBeNull()
        ->and(session(FacilityContext::SESSION_KEY))->toBeNull()
        ->and(app(FacilityContext::class)->current(request(), app(OrganizationContext::class)))->toBeNull();
});

it('revokes a removed facility assignment on the next request without deactivating organization membership', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create();
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id,
        'facility_id' => $facility->id,
        'user_id' => $user->id,
        'role' => 'staff',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id]);

    $this->get(route('dashboard'))->assertOk();
    expect(session(FacilityContext::SESSION_KEY))->toBe($facility->id);

    DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
        ->where('facility_id', $facility->id)->where('user_id', $user->id)->delete();

    $this->get(route('dashboard'))->assertOk();
    expect(session(FacilityContext::SESSION_KEY))->toBeNull()
        ->and(session(OrganizationContext::SESSION_KEY))->toBe($organization->id);
});

it('keeps another organization accessible after a signed-in membership is revoked', function () {
    $first = Organization::factory()->create(['status' => 'active']);
    $second = Organization::factory()->create(['status' => 'active']);
    $user = User::factory()->create();
    $user->organizations()->attach($first, ['role' => 'staff', 'status' => 'active']);
    $user->organizations()->attach($second, ['role' => 'staff', 'status' => 'active']);

    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $first->id]);
    $firstFacility = Facility::factory()->create();
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $first->id, 'facility_id' => $firstFacility->id,
        'user_id' => $user->id, 'role' => 'clinician',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Create the second facility only after switching to its authorized tenant.
    $this->withSession([OrganizationContext::SESSION_KEY => $second->id]);
    $secondFacility = Facility::factory()->create();
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $second->id, 'facility_id' => $secondFacility->id,
        'user_id' => $user->id, 'role' => 'staff',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Simulate an existing browser session at the first facility before revocation.
    $this->withSession([
        OrganizationContext::SESSION_KEY => $first->id,
        FacilityContext::SESSION_KEY => $firstFacility->id,
    ]);
    $this->get(route('dashboard'))->assertOk();
    expect(session(FacilityContext::SESSION_KEY))->toBe($firstFacility->id);

    $user->organizations()->updateExistingPivot($first->id, ['status' => 'inactive']);

    $this->get(route('dashboard'))->assertOk();
    expect(session(OrganizationContext::SESSION_KEY))->toBe($second->id)
        ->and(session(FacilityContext::SESSION_KEY))->toBeNull();

    $this->post(route('organizations.switch'), ['organization_id' => $first->id])->assertForbidden();
    $this->post(route('facilities.switch'), ['facility_id' => $firstFacility->id])->assertForbidden();
    $this->post(route('facilities.switch'), ['facility_id' => $secondFacility->id])
        ->assertRedirect(route('dashboard'));
    expect(session(FacilityContext::SESSION_KEY))->toBe($secondFacility->id);
});

it('denies selecting a removed clinical facility while keeping the active organization', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create();
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id, 'facility_id' => $facility->id,
        'user_id' => $user->id, 'role' => 'clinician',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->post(route('facilities.switch'), ['facility_id' => $facility->id])
        ->assertRedirect(route('dashboard'));

    DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
        ->where('facility_id', $facility->id)->where('user_id', $user->id)->delete();

    $this->post(route('facilities.switch'), ['facility_id' => $facility->id])->assertForbidden();
    $this->get(route('dashboard'))->assertOk();
    expect(session(OrganizationContext::SESSION_KEY))->toBe($organization->id)
        ->and(session(FacilityContext::SESSION_KEY))->toBeNull();
});
