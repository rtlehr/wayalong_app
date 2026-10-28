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

function overviewSetup(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id, 'facility_id' => $facility->id,
        'user_id' => $user->id, 'role' => 'clinician', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $createClient = function (string $name) use ($facility): ClientEnrollment {
        $person = Person::create(['first_name' => $name, 'last_name' => 'Demo', 'person_type' => 'client', 'facility_id' => $facility->id]);
        return ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'status' => 'active', 'enrolled_at' => today()]);
    };
    $assigned = $createClient('Assigned');
    $unassigned = $createClient('Unassigned');
    DB::table('client_staff_assignments')->insert([
        'organization_id' => $organization->id, 'client_enrollment_id' => $assigned->id,
        'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    return [$assigned, $unassigned];
}

it('shows current alerts and latest shift summary without loading clinical history', function () {
    [$assigned] = overviewSetup();
    $this->post(route('facility.clients.alerts.store', $assigned), [
        'category' => 'behavioral', 'priority' => 'high', 'title' => 'Current concern', 'details' => 'Watch for triggers',
    ])->assertRedirect();
    $this->post(route('facility.clients.treatment.shift-notes.store', $assigned), [
        'shift_date' => today()->toDateString(), 'shift' => 'day', 'summary' => 'Earlier summary',
    ])->assertRedirect();
    $this->post(route('facility.clients.treatment.shift-notes.store', $assigned), [
        'shift_date' => today()->toDateString(), 'shift' => 'evening', 'summary' => 'Most recent summary', 'handoff' => 'Monitor tonight',
    ])->assertRedirect();
    $this->get(route('facility.clients.treatment.overview', $assigned))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('facilities/ClientTreatmentOverview')
        ->where('activeAlertCount', 1)
        ->where('alerts.0.title', 'Current concern')
        ->where('latestShiftNote.summary', 'Most recent summary')
        ->where('latestShiftNote.handoff', 'Monitor tonight')
        ->missing('alerts.0.events')
        ->etc());
});

it('blocks the overview for unassigned clients and unauthenticated users', function () {
    [$assigned, $unassigned] = overviewSetup();
    $this->get(route('facility.clients.treatment.overview', $unassigned))->assertForbidden();
    $this->get(route('facility.clients.treatment.overview', $assigned))->assertOk();
    auth()->logout();
    $this->get(route('facility.clients.treatment.overview', $assigned))->assertRedirect();
});


it('permits ordinary treatment views for owners without client assignment but not administrators', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $organization->users()->attach($admin, ['role' => 'admin', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    $person = Person::create(['first_name' => 'Owner', 'last_name' => 'Example', 'person_type' => 'client', 'facility_id' => $facility->id]);
    $client = ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'status' => 'active', 'enrolled_at' => today()]);

    $this->get(route('facility.clients.treatment.overview', $client))->assertOk();
    $this->get(route('facility.clients.treatment.alerts', $client))->assertOk();
    $this->get(route('facility.clients.treatment.shift-notes', $client))->assertOk();

    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $this->get(route('facility.clients.treatment.overview', $client))->assertForbidden();
    $this->get(route('facility.clients.treatment.alerts', $client))->assertForbidden();
    $this->get(route('facility.clients.treatment.shift-notes', $client))->assertForbidden();
});
