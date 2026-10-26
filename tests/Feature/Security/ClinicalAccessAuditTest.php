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

function clinicalAuditSetup(): array
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

    $client = function (string $name) use ($facility): ClientEnrollment {
        $person = Person::create(['first_name' => $name, 'last_name' => 'Demo', 'person_type' => 'client', 'facility_id' => $facility->id]);
        return ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'status' => 'active', 'enrolled_at' => today()]);
    };

    $assigned = $client('Assigned');
    $unassigned = $client('Unassigned');
    DB::table('client_staff_assignments')->insert([
        'organization_id' => $organization->id, 'client_enrollment_id' => $assigned->id,
        'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$organization, $user, $assigned, $unassigned];
}

it('records authorized clinical views without storing clinical content', function () {
    [$organization, $user, $assigned] = clinicalAuditSetup();

    $this->get(route('facility.clients.treatment.overview', $assigned))->assertOk();
    $this->get(route('facility.clients.treatment.alerts', $assigned))->assertOk();
    $this->get(route('facility.clients.treatment.shift-notes', $assigned))->assertOk();

    $events = DB::table('clinical_access_events')->orderBy('id')->get();
    expect($events)->toHaveCount(3);
    expect($events->pluck('section')->all())->toBe(['overview', 'alerts', 'shift_notes']);
    expect($events->pluck('outcome')->unique()->all())->toBe(['viewed']);
    expect($events->pluck('organization_id')->unique()->all())->toBe([$organization->id]);
    expect($events->pluck('user_id')->unique()->all())->toBe([$user->id]);
    expect($events->pluck('client_enrollment_id')->unique()->all())->toBe([$assigned->id]);
    expect(array_keys((array) $events->first()))->toBe([
        'id', 'organization_id', 'client_enrollment_id', 'user_id', 'section', 'outcome', 'created_at',
    ]);
});

it('audits denied assigned-client access attempts without granting access', function () {
    [$organization, $user, , $unassigned] = clinicalAuditSetup();

    $this->get(route('facility.clients.treatment.alerts', $unassigned))->assertForbidden();

    $this->assertDatabaseHas('clinical_access_events', [
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'client_enrollment_id' => $unassigned->id,
        'section' => 'alerts',
        'outcome' => 'denied',
    ]);
});

it('does not create a clinical access event for unauthenticated requests', function () {
    [, , $assigned] = clinicalAuditSetup();
    auth()->logout();

    $this->get(route('facility.clients.treatment.overview', $assigned))->assertRedirect();

    expect(DB::table('clinical_access_events')->count())->toBe(0);
});
