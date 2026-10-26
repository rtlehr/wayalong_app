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

function clientFacility(Organization $organization, User $user, string $role = 'client_admin'): Facility
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

    $person = Person::where('person_type', 'client')->get()->first(fn (Person $person) => $person->first_name === 'Jordan') ?? throw new RuntimeException('Expected newly enrolled client');
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
    expect(Person::query()->get()->contains(fn (Person $person) => $person->first_name === 'Blocked'))->toBeFalse();
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


it('limits clinician directory and profile access to assigned clients and blocks administrative writes', function () {
    $organization = Organization::factory()->create();
    $clinician = User::factory()->create();
    $organization->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = clientFacility($organization, $clinician, 'clinician');
    $assigned = ClientEnrollment::create([
        'person_id' => Person::create(['first_name' => 'Assigned', 'last_name' => 'Youth', 'person_type' => 'client', 'facility_id' => $facility->id])->id,
        'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString(),
    ]);
    $unassigned = ClientEnrollment::create([
        'person_id' => Person::create(['first_name' => 'Unassigned', 'last_name' => 'Youth', 'person_type' => 'client', 'facility_id' => $facility->id])->id,
        'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString(),
    ]);
    DB::table('client_staff_assignments')->insert([
        'organization_id' => $organization->id, 'client_enrollment_id' => $assigned->id,
        'user_id' => $clinician->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id]);
    $this->get(route('facility.clients.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('canManage', false)->where('clients.total', 1)
        ->where('clients.data.0.id', $assigned->id)->etc());
    $this->get(route('facility.clients.index', ['search' => 'Unassigned']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('clients.total', 0)->etc());
    $this->get(route('facility.clients.show', $assigned))->assertOk();
    $this->get(route('facility.clients.show', $unassigned))->assertForbidden();
    $this->get(route('facility.clients.create'))->assertForbidden();
    $this->get(route('facility.clients.edit', $assigned))->assertForbidden();
    $this->post(route('facility.clients.store'), [
        'first_name' => 'Should', 'last_name' => 'Fail', 'enrolled_at' => today()->toDateString(),
    ])->assertForbidden();
    $this->patch(route('facility.clients.profile.update', $assigned), [
        'first_name' => 'Should', 'last_name' => 'Fail',
    ])->assertForbidden();
    $this->patch(route('facility.clients.update', $assigned), ['status' => 'discharged'])->assertForbidden();
});

it('lets a facility-assigned owner manage client administration without granting treatment rights', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = clientFacility($organization, $owner, 'owner');
    $otherFacility = Facility::factory()->create(['organization_id' => $organization->id]);

    $client = ClientEnrollment::create([
        'person_id' => Person::create([
            'first_name' => 'Administrative', 'last_name' => 'Client',
            'person_type' => 'client', 'facility_id' => $facility->id,
        ])->id,
        'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString(),
    ]);
    $otherClient = ClientEnrollment::create([
        'person_id' => Person::create([
            'first_name' => 'Other', 'last_name' => 'Facility',
            'person_type' => 'client', 'facility_id' => $otherFacility->id,
        ])->id,
        'facility_id' => $otherFacility->id, 'enrolled_at' => today()->toDateString(),
    ]);

    $this->withSession([FacilityContext::SESSION_KEY => $facility->id]);
    $this->get(route('facility.clients.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', true)->etc());
    $this->get(route('facility.clients.show', $client))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', true)->etc());
    $this->get(route('facility.clients.edit', $client))->assertOk();
    $this->get(route('facility.clients.show', $otherClient))->assertNotFound();
    $this->get(route('facility.clients.treatment.overview', $client))->assertForbidden();
});
