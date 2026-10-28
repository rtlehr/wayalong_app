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

beforeEach(fn () => $this->withoutVite());

it('does not advertise client treatment to a facility administrator without clinical authorization', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $organization->users()->attach($admin, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id,
        'facility_id' => $facility->id,
        'user_id' => $admin->id,
        'role' => 'facility_admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // The authenticated organization context is established before creating scoped models.
    session()->put(FacilityContext::SESSION_KEY, $facility->id);
    $person = Person::create([
        'first_name' => 'Admin', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $facility->id,
    ]);
    $client = ClientEnrollment::create([
        'person_id' => $person->id, 'facility_id' => $facility->id,
        'status' => 'active', 'enrolled_at' => today()->toDateString(),
    ]);

    $this->actingAs($admin)->withSession([
        OrganizationContext::SESSION_KEY => $organization->id,
        FacilityContext::SESSION_KEY => $facility->id,
    ]);
    $this->get(route('facility.clients.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('facilities/Clients')
        ->where('clients.data.0.id', $client->id)
        ->where('treatmentAccess.'.$client->id, false)
        ->etc());
    $this->get(route('facility.clients.treatment.overview', $client))->assertForbidden();
});

it('advertises treatment only for active assigned clients when a clinician has clinical permissions', function () {
    $organization = Organization::factory()->create();
    $clinician = User::factory()->create();
    $organization->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id,
        'facility_id' => $facility->id,
        'user_id' => $clinician->id,
        'role' => 'clinician',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // The authenticated organization context is established before creating scoped models.
    session()->put(FacilityContext::SESSION_KEY, $facility->id);
    $makeClient = function (string $name, string $status) use ($facility): ClientEnrollment {
        $person = Person::create(['first_name' => $name, 'last_name' => 'Example', 'person_type' => 'client', 'facility_id' => $facility->id]);
        return ClientEnrollment::create([
            'person_id' => $person->id, 'facility_id' => $facility->id,
            'status' => $status, 'enrolled_at' => today()->toDateString(),
        ]);
    };
    $active = $makeClient('Active', 'active');
    $discharged = $makeClient('Discharged', 'discharged');
    foreach ([$active, $discharged] as $client) {
        DB::table('client_staff_assignments')->insert([
            'organization_id' => $organization->id, 'client_enrollment_id' => $client->id,
            'user_id' => $clinician->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->actingAs($clinician)->withSession([
        OrganizationContext::SESSION_KEY => $organization->id,
        FacilityContext::SESSION_KEY => $facility->id,
    ]);
    $this->get(route('facility.clients.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('facilities/Clients')
        ->where('treatmentAccess.'.$active->id, true)
        ->where('treatmentAccess.'.$discharged->id, false)
        ->etc());
});
