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
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);
beforeEach(function () { $this->withoutVite(); });

function assignmentAuditFixture(): array
{
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $clinician = User::factory()->create();
    $organization->users()->attach($actor, ['role' => 'staff', 'status' => 'active']);
    $organization->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    test()->actingAs($actor)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    foreach ([$actor->id => 'client_admin', $clinician->id => 'clinician'] as $userId => $role) {
        DB::table('organization_staff_facilities')->insert([
            'organization_id' => $organization->id, 'facility_id' => $facility->id,
            'user_id' => $userId, 'role' => $role, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    test()->withSession([FacilityContext::SESSION_KEY => $facility->id]);
    $person = Person::create(['first_name' => 'Audit', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $facility->id]);
    $enrollment = ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString()]);
    return [$organization, $actor, $clinician, $facility, $enrollment];
}

it('logs an audit event when staff are assigned to a client', function () {
    [$org, $actor, $clinician, $facility, $enrollment] = assignmentAuditFixture();
    Log::spy();

    $this->patch(route('facility.clients.profile.update', $enrollment), [
        'first_name' => 'Audit', 'last_name' => 'Client',
        'contacts' => [], 'guardian_ids' => [], 'staff_ids' => [$clinician->id],
    ])->assertRedirect();

    Log::shouldHaveReceived('info')->once()->with('WayAlong client staff assignment created', Mockery::on(
        fn (array $data) => $data['actor_id'] === $actor->id
            && $data['subject_id'] === $clinician->id
            && $data['organization_id'] === $org->id
            && $data['facility_id'] === $facility->id
            && $data['client_enrollment_id'] === $enrollment->id
            && $data['action'] === 'client_staff_assignment.created'
    ));
    $this->assertDatabaseHas('client_staff_assignments', [
        'client_enrollment_id' => $enrollment->id, 'user_id' => $clinician->id,
    ]);
});

it('does not log assignment changes when the assigned staff are unchanged', function () {
    [$org, $actor, $clinician, $facility, $enrollment] = assignmentAuditFixture();
    DB::table('client_staff_assignments')->insert([
        'organization_id' => $org->id,
        'client_enrollment_id' => $enrollment->id,
        'user_id' => $clinician->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Capture only the assignment audit messages. Other info logs are permitted.
    $assignmentEvents = [];
    Log::shouldReceive('info')->zeroOrMoreTimes()->andReturnUsing(
        function ($message, $context = []) use (&$assignmentEvents): void {
            if (in_array($message, [
                'WayAlong client staff assignment created',
                'WayAlong client staff assignment removed',
            ], true)) {
                $assignmentEvents[] = [$message, $context];
            }
        }
    );

    $this->patch(route('facility.clients.profile.update', $enrollment), [
        'first_name' => 'Audit', 'last_name' => 'Client',
        'contacts' => [], 'guardian_ids' => [], 'staff_ids' => [$clinician->id],
    ])->assertRedirect();

    expect($assignmentEvents)->toBe([]);
    $this->assertDatabaseHas('client_staff_assignments', [
        'client_enrollment_id' => $enrollment->id, 'user_id' => $clinician->id,
    ]);
});

it('logs an audit event when staff are removed from a client', function () {
    [$org, $actor, $clinician, $facility, $enrollment] = assignmentAuditFixture();
    DB::table('client_staff_assignments')->insert([
        'organization_id' => $org->id,
        'client_enrollment_id' => $enrollment->id,
        'user_id' => $clinician->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Log::spy();

    $this->patch(route('facility.clients.profile.update', $enrollment), [
        'first_name' => 'Audit', 'last_name' => 'Client',
        'contacts' => [], 'guardian_ids' => [], 'staff_ids' => [],
    ])->assertRedirect();

    Log::shouldHaveReceived('info')->once()->with('WayAlong client staff assignment removed', Mockery::on(
        fn (array $data) => $data['actor_id'] === $actor->id
            && $data['subject_id'] === $clinician->id
            && $data['organization_id'] === $org->id
            && $data['facility_id'] === $facility->id
            && $data['client_enrollment_id'] === $enrollment->id
            && $data['action'] === 'client_staff_assignment.removed'
    ));
    $this->assertDatabaseMissing('client_staff_assignments', [
        'client_enrollment_id' => $enrollment->id, 'user_id' => $clinician->id,
    ]);
});
