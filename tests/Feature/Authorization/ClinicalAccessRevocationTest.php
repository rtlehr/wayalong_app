<?php

use App\Models\ClientEnrollment;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->withoutVite());

function clinicalRevocationFixture(): array
{
    $organization = Organization::factory()->create();
    $clinician = User::factory()->create();
    $organization->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    test()->actingAs($clinician)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);

    $facilities = [];
    $clients = [];
    foreach (['First', 'Second'] as $label) {
        $facility = Facility::factory()->create(['organization_id' => $organization->id]);
        DB::table('organization_staff_facilities')->insert([
            'organization_id' => $organization->id, 'facility_id' => $facility->id,
            'user_id' => $clinician->id, 'role' => 'clinician',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $person = Person::create([
            'first_name' => $label, 'last_name' => 'Client',
            'person_type' => 'client', 'facility_id' => $facility->id,
        ]);
        $client = ClientEnrollment::create([
            'person_id' => $person->id, 'facility_id' => $facility->id,
            'status' => 'active', 'enrolled_at' => today(),
        ]);
        DB::table('client_staff_assignments')->insert([
            'organization_id' => $organization->id, 'client_enrollment_id' => $client->id,
            'user_id' => $clinician->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $facilities[] = $facility;
        $clients[] = $client;
    }

    return [$organization, $clinician, $facilities, $clients];
}

it('immediately denies direct clinical reads and writes after client assignment revocation in an existing session', function () {
    [$organization, $clinician, , $clients] = clinicalRevocationFixture();
    $client = $clients[0];

    $this->get(route('facility.clients.treatment.overview', $client))->assertOk();
    $this->get(route('facility.clients.treatment.alerts', $client))->assertOk();
    $this->get(route('facility.clients.treatment.shift-notes', $client))->assertOk();

    DB::table('client_staff_assignments')->where('organization_id', $organization->id)
        ->where('client_enrollment_id', $client->id)->where('user_id', $clinician->id)->delete();

    $this->get(route('facility.clients.treatment.overview', $client))->assertForbidden();
    $this->get(route('facility.clients.treatment.alerts', $client))->assertForbidden();
    $this->get(route('facility.clients.treatment.shift-notes', $client))->assertForbidden();
    $this->post(route('facility.clients.alerts.store', $client), [
        'category' => 'general', 'priority' => 'warning', 'title' => 'Must not be written',
    ])->assertForbidden();
    $this->post(route('facility.clients.treatment.shift-notes.store', $client), [
        'shift_date' => today()->toDateString(), 'shift' => 'day', 'summary' => 'Must not be written',
    ])->assertForbidden();
    expect(DB::table('client_alerts')->where('client_enrollment_id', $client->id)->count())->toBe(0)
        ->and(DB::table('client_shift_notes')->where('client_enrollment_id', $client->id)->count())->toBe(0);

    // The same signed-in clinician remains authorized for the second client.
    $this->get(route('facility.clients.treatment.overview', $clients[1]))->assertOk();
});

it('immediately denies clinical data after facility assignment revocation without affecting another facility', function () {
    [$organization, $clinician, $facilities, $clients] = clinicalRevocationFixture();

    $this->get(route('facility.clients.treatment.overview', $clients[0]))->assertOk();
    DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
        ->where('facility_id', $facilities[0]->id)->where('user_id', $clinician->id)->delete();

    // Client assignment is deliberately retained: facility access must still be required.
    expect(DB::table('client_staff_assignments')->where('client_enrollment_id', $clients[0]->id)
        ->where('user_id', $clinician->id)->exists())->toBeTrue();
    $this->get(route('facility.clients.treatment.overview', $clients[0]))->assertForbidden();
    $this->get(route('facility.clients.treatment.alerts', $clients[0]))->assertForbidden();
    $this->get(route('facility.clients.treatment.shift-notes', $clients[0]))->assertForbidden();
    $this->post(route('facility.clients.alerts.store', $clients[0]), [
        'category' => 'general', 'priority' => 'warning', 'title' => 'Must not be written',
    ])->assertForbidden();
    $this->get(route('facility.clients.treatment.overview', $clients[1]))->assertOk();
});
