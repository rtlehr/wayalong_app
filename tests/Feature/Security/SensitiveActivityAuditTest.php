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

uses(RefreshDatabase::class);
beforeEach(fn () => $this->withoutVite());

function sensitiveActivitySetup(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id, 'facility_id' => $facility->id,
        'user_id' => $user->id, 'role' => 'client_admin', 'created_at' => now(), 'updated_at' => now(),
    ]);
    test()->withSession([FacilityContext::SESSION_KEY => $facility->id]);
    $person = Person::create(['first_name' => 'Protected', 'last_name' => 'Record', 'person_type' => 'client', 'facility_id' => $facility->id]);
    $enrollment = ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'enrolled_at' => today()]);

    return [$organization, $user, $facility, $enrollment];
}

it('records authorized client administration views and edits as access events without clinical content', function () {
    [$organization, $user, , $enrollment] = sensitiveActivitySetup();
    $this->get(route('facility.clients.show', $enrollment))->assertOk();
    $this->get(route('facility.clients.edit', $enrollment))->assertOk();

    $events = DB::table('clinical_access_events')->where('section', 'admin_profile')->get();
    expect($events)->toHaveCount(2);
    expect($events->pluck('organization_id')->unique()->all())->toBe([$organization->id]);
    expect($events->pluck('user_id')->unique()->all())->toBe([$user->id]);
    expect($events->pluck('client_enrollment_id')->unique()->all())->toBe([$enrollment->id]);
    expect(array_keys((array) $events->first()))->toBe([
        'id', 'organization_id', 'client_enrollment_id', 'user_id', 'section', 'outcome', 'created_at',
    ]);
});

it('audits an authorized directory export and denies exporting a foreign client', function () {
    [$organization, $user, $facility, $enrollment] = sensitiveActivitySetup();
    $this->post(route('facility.clients.audit-export'), ['enrollment_ids' => [$enrollment->id]])->assertNoContent();
    $this->assertDatabaseHas('clinical_access_events', [
        'organization_id' => $organization->id, 'user_id' => $user->id,
        'client_enrollment_id' => $enrollment->id, 'section' => 'directory_export', 'outcome' => 'exported',
    ]);

    $otherFacility = Facility::factory()->create(['organization_id' => $organization->id]);
    $otherPerson = Person::create(['first_name' => 'Other', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $otherFacility->id]);
    $otherEnrollment = ClientEnrollment::create(['person_id' => $otherPerson->id, 'facility_id' => $otherFacility->id, 'enrolled_at' => today()]);
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id]);
    $this->post(route('facility.clients.audit-export'), ['enrollment_ids' => [$otherEnrollment->id]])->assertForbidden();
    expect(DB::table('clinical_access_events')->where('section', 'directory_export')->count())->toBe(1);
});

it('reports retention candidates without deleting access events', function () {
    [$organization, $user, , $enrollment] = sensitiveActivitySetup();
    DB::table('clinical_access_events')->insert([
        'organization_id' => $organization->id, 'user_id' => $user->id,
        'client_enrollment_id' => $enrollment->id, 'section' => 'overview',
        'outcome' => 'viewed', 'created_at' => now()->subDays(400),
    ]);
    $this->artisan('wayalong:audit-retention-review')->assertExitCode(0);
    expect(DB::table('clinical_access_events')->count())->toBe(1);
});
