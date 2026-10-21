<?php

use App\Models\ClientEnrollment;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use App\Support\FacilityContext;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () { $this->withoutVite(); });

function clientFacility(Organization $organization, User $user, string $role = 'clinician'): Facility
{
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id,
        'facility_id' => $facility->id,
        'user_id' => $user->id,
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $facility;
}

it('enrolls a youth without an email address or login account', function () {
    $organization = Organization::factory()->create();
    $clinician = User::factory()->create();
    $organization->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = clientFacility($organization, $clinician);

    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])
        ->post(route('facility.clients.store'), [
            'first_name' => 'Jordan', 'last_name' => 'Example', 'date_of_birth' => '2012-08-04',
            'enrolled_at' => today()->toDateString(),
        ])->assertRedirect(route('facility.clients.index'));

    $person = Person::where('first_name', 'Jordan')->firstOrFail();
    expect($person->user_id)->toBeNull()
        ->and($person->facility_id)->toBe($facility->id)
        ->and($person->person_type)->toBe('client');
    $this->assertDatabaseHas('client_enrollments', [
        'person_id' => $person->id, 'organization_id' => $organization->id,
        'facility_id' => $facility->id, 'status' => 'active',
    ]);
});

it('shows only the selected facility clients', function () {
    $organization = Organization::factory()->create();
    $clinician = User::factory()->create();
    $organization->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $here = clientFacility($organization, $clinician);
    $elsewhere = clientFacility($organization, $clinician);
    foreach ([$here, $elsewhere] as $facility) {
        $person = Person::create(['first_name' => 'Client', 'last_name' => $facility->name, 'person_type' => 'client', 'facility_id' => $facility->id]);
        ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString()]);
    }

    $this->withSession([FacilityContext::SESSION_KEY => $here->id])
        ->get(route('facility.clients.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('facilities/Clients')
            ->where('facility.id', $here->id)->has('clients.data', 1)->etc());
});

it('denies staff without client permissions and denies unassigned facilities', function () {
    $organization = Organization::factory()->create();
    $staff = User::factory()->create();
    $organization->users()->attach($staff, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($staff)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $staffFacility = clientFacility($organization, $staff, 'staff');
    $notAssigned = Facility::factory()->create(['organization_id' => $organization->id]);

    $this->withSession([FacilityContext::SESSION_KEY => $staffFacility->id])
        ->get(route('facility.clients.index'))->assertForbidden();
    $this->withSession([FacilityContext::SESSION_KEY => $staffFacility->id])
        ->post(route('facility.clients.store'), ['first_name' => 'Blocked', 'last_name' => 'Client', 'enrolled_at' => today()->toDateString()])
        ->assertForbidden();
    $this->withSession([FacilityContext::SESSION_KEY => $notAssigned->id])
        ->get(route('facility.clients.index'))->assertForbidden();
    $this->assertDatabaseMissing('people', ['first_name' => 'Blocked']);
});

it('prevents changing enrollment status outside the selected facility', function () {
    $organization = Organization::factory()->create();
    $clinician = User::factory()->create();
    $organization->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $first = clientFacility($organization, $clinician);
    $second = clientFacility($organization, $clinician);
    $person = Person::create(['first_name' => 'Example', 'last_name' => 'Patient', 'person_type' => 'client', 'facility_id' => $second->id]);
    $enrollment = ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $second->id, 'enrolled_at' => today()->subWeek()->toDateString()]);

    $this->withSession([FacilityContext::SESSION_KEY => $first->id])
        ->patch(route('facility.clients.update', $enrollment), ['status' => 'discharged', 'discharged_at' => today()->toDateString()])
        ->assertNotFound();
    expect($enrollment->fresh()->status)->toBe('active');

    $this->withSession([FacilityContext::SESSION_KEY => $second->id])
        ->patch(route('facility.clients.update', $enrollment), ['status' => 'discharged', 'discharged_at' => today()->toDateString()])
        ->assertRedirect(route('facility.clients.index'));
    expect($enrollment->fresh()->status)->toBe('discharged');
});

it('revokes client visibility after organization membership is deactivated', function () {
    $organization = Organization::factory()->create();
    $clinician = User::factory()->create();
    $organization->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = clientFacility($organization, $clinician);
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])->get(route('facility.clients.index'))->assertOk();
    $organization->users()->updateExistingPivot($clinician->id, ['status' => 'inactive']);
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])->get(route('facility.clients.index'))->assertForbidden();
});


it('paginates client directory records without exposing another facility', function () {
    $organization = Organization::factory()->create();
    $clinician = User::factory()->create();
    $organization->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = clientFacility($organization, $clinician);
    $other = clientFacility($organization, $clinician);
    foreach (range(1, 17) as $index) {
        $person = Person::create(['first_name' => 'Directory', 'last_name' => 'Client'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'person_type' => 'client', 'facility_id' => $facility->id]);
        ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString()]);
    }
    $outsidePerson = Person::create(['first_name' => 'Outside', 'last_name' => 'Facility', 'person_type' => 'client', 'facility_id' => $other->id]);
    ClientEnrollment::create(['person_id' => $outsidePerson->id, 'facility_id' => $other->id, 'enrolled_at' => today()->toDateString()]);

    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])
        ->get(route('facility.clients.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('facilities/Clients')->where('clients.total', 17)->has('clients.data', 15)->etc());
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])
        ->get(route('facility.clients.index', ['page' => 2]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('facilities/Clients')->where('clients.total', 17)->has('clients.data', 2)->etc());
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])
        ->get(route('facility.clients.index', ['search' => 'Outside']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('facilities/Clients')->has('clients.data', 0)->etc());
});
