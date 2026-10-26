<?php

use App\Models\ClientEnrollment;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->withoutVite());

function dashboardClient(Organization $organization, Facility $facility, string $name): ClientEnrollment
{
    $person = Person::create(['first_name' => $name, 'last_name' => 'Example', 'person_type' => 'client', 'facility_id' => $facility->id]);
    return ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'status' => 'active', 'enrolled_at' => today()->toDateString()]);
}

it('shows only assigned active clients of authorized facilities in the selected organization', function () {
    $org = Organization::factory()->create();
    $clinician = User::factory()->create();
    $org->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $facility = Facility::factory()->create(['organization_id' => $org->id]);
    DB::table('organization_staff_facilities')->insert(['organization_id' => $org->id, 'facility_id' => $facility->id, 'user_id' => $clinician->id, 'role' => 'clinician', 'created_at' => now(), 'updated_at' => now()]);
    $mine = dashboardClient($org, $facility, 'Mine');
    dashboardClient($org, $facility, 'NotAssigned');
    $inactive = dashboardClient($org, $facility, 'Inactive');
    $inactive->update(['status' => 'discharged']);
    foreach ([$mine, $inactive] as $enrollment) {
        DB::table('client_staff_assignments')->insert(['organization_id' => $org->id, 'client_enrollment_id' => $enrollment->id, 'user_id' => $clinician->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $org->id])
        ->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->where('canViewClinicalClients', true)
            ->has('myClients', 1)->where('myClients.0.name', 'Mine Example')
            ->where('myClients.0.id', $mine->id)
            ->where('myClients.0.enrolled_on', today()->toDateString())->etc());
});

it('does not expose clinical caseloads to organization owners without clinical facility permission', function () {
    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $org->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $facility = Facility::factory()->create(['organization_id' => $org->id]);
    DB::table('organization_staff_facilities')->insert(['organization_id' => $org->id, 'facility_id' => $facility->id, 'user_id' => $owner->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
    $client = dashboardClient($org, $facility, 'Private');
    DB::table('client_staff_assignments')->insert(['organization_id' => $org->id, 'client_enrollment_id' => $client->id, 'user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id])
        ->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->where('canViewClinicalClients', false)->has('myClients', 0)->etc());
});

it('does not turn a facility owner administrative view into access to the clinical caseload', function () {
    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $org->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $facility = Facility::factory()->create(['organization_id' => $org->id]);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $org->id, 'facility_id' => $facility->id,
        'user_id' => $owner->id, 'role' => 'owner',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $permissions = app(\App\Support\FacilityPermissions::class);
    expect($permissions->allows($owner, $facility, \App\Support\FacilityPermissions::VIEW_CLIENTS))->toBeTrue()
        ->and($permissions->allows($owner, $facility, \App\Support\FacilityPermissions::MANAGE_CLIENTS))->toBeTrue()
        ->and($permissions->allows($owner, $facility, \App\Support\FacilityPermissions::VIEW_CLINICAL_CASELOAD))->toBeFalse();

    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->where('canViewClinicalClients', false)->has('myClients', 0)->etc());
});

it('does not disclose assigned clients from another organization', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $clinician = User::factory()->create();
    foreach ([$first, $second] as $org) $org->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $first->id]);
    foreach ([$first, $second] as $org) {
        // Organization-owned fixtures must be created in that organization's context.
        session()->put(OrganizationContext::SESSION_KEY, $org->id);
        $facility = Facility::factory()->create(['organization_id' => $org->id]);
        DB::table('organization_staff_facilities')->insert(['organization_id' => $org->id, 'facility_id' => $facility->id, 'user_id' => $clinician->id, 'role' => 'clinician', 'created_at' => now(), 'updated_at' => now()]);
        $enrollment = dashboardClient($org, $facility, $org->id === $first->id ? 'First' : 'Second');
        DB::table('client_staff_assignments')->insert(['organization_id' => $org->id, 'client_enrollment_id' => $enrollment->id, 'user_id' => $clinician->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $first->id])
        ->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->has('myClients', 1)->where('myClients.0.name', 'First Example')->etc());
});


it('returns contact previews only for clients assigned to the clinician', function () {
    $org = Organization::factory()->create();
    $clinician = User::factory()->create();
    $org->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $facility = Facility::factory()->create(['organization_id' => $org->id]);
    DB::table('organization_staff_facilities')->insert(['organization_id' => $org->id, 'facility_id' => $facility->id, 'user_id' => $clinician->id, 'role' => 'clinician', 'created_at' => now(), 'updated_at' => now()]);
    $mine = dashboardClient($org, $facility, 'Assigned');
    $other = dashboardClient($org, $facility, 'Unassigned');
    foreach ([$mine, $other] as $enrollment) {
        $enrollment->person->contactMethods()->create(['kind' => 'phone', 'label' => 'Mobile', 'is_primary' => true, 'value' => $enrollment->id === $mine->id ? '555-0101' : '555-9999']);
    }
    DB::table('client_staff_assignments')->insert(['organization_id' => $org->id, 'client_enrollment_id' => $mine->id, 'user_id' => $clinician->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->has('myClients', 1)
        ->where('myClients.0.phone', '555-0101')
        ->where('myClients.0.email', null)
        ->etc());
});
