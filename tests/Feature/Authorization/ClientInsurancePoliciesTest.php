<?php

use App\Models\ClientEnrollment;
use App\Models\ClientInsurancePolicy;
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

function insuranceSetup(): array
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $facility = Facility::factory()->create();
    DB::table('organization_staff_facilities')->insert(['organization_id' => $org->id, 'facility_id' => $facility->id, 'user_id' => $user->id, 'role' => 'clinician', 'created_at' => now(), 'updated_at' => now()]);
    test()->withSession([FacilityContext::SESSION_KEY => $facility->id]);
    $person = Person::create(['first_name' => 'Youth', 'last_name' => 'Example', 'person_type' => 'client', 'facility_id' => $facility->id]);
    $enrollment = ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString()]);

    return [$org, $user, $facility, $person, $enrollment];
}

it('stores organization-level encrypted coverage and displays it to authorized facility staff', function () {
    [, , , $person, $enrollment] = insuranceSetup();
    $this->post(route('facility.clients.insurance.store', $enrollment), ['payer_name' => 'Example Health', 'coverage_order' => 'primary', 'member_id' => 'PRIVATE-MEMBER-123', 'verification_status' => 'unverified'])->assertRedirect();
    $policy = ClientInsurancePolicy::firstOrFail();
    expect($policy->person_id)->toBe($person->id)->and($policy->member_id)->toBe('PRIVATE-MEMBER-123');
    expect(DB::table('client_insurance_policies')->value('member_id'))->not->toContain('PRIVATE-MEMBER-123');
    $this->get(route('facility.clients.show', $enrollment))->assertOk()->assertInertia(fn (Assert $page) => $page->component('facilities/ClientProfile')->where('insurancePolicies.0.payer_name', 'Example Health')->etc());
});

it('limits coverage priority to one policy per client and validates dates', function () {
    [, , , , $enrollment] = insuranceSetup();
    $payload = ['payer_name' => 'Payer', 'coverage_order' => 'primary', 'verification_status' => 'unverified'];
    $this->post(route('facility.clients.insurance.store', $enrollment), $payload)->assertRedirect();
    $this->post(route('facility.clients.insurance.store', $enrollment), $payload)->assertSessionHasErrors('coverage_order');
    $this->post(route('facility.clients.insurance.store', $enrollment), [...$payload, 'coverage_order' => 'secondary', 'effective_on' => '2026-01-02', 'expires_on' => '2025-01-01'])->assertSessionHasErrors('expires_on');
    expect(ClientInsurancePolicy::count())->toBe(1);
});

it('allows updating and deleting only a policy attached to the authorized client', function () {
    [$org, , $facility, $person, $enrollment] = insuranceSetup();
    $policy = ClientInsurancePolicy::create(['person_id' => $person->id, 'payer_name' => 'First', 'coverage_order' => 'primary', 'verification_status' => 'pending']);
    $this->patch(route('facility.clients.insurance.update', [$enrollment, $policy]), ['payer_name' => 'Updated', 'coverage_order' => 'primary', 'verification_status' => 'verified', 'verified_on' => today()->toDateString()])->assertRedirect();
    expect($policy->fresh()->payer_name)->toBe('Updated');
    $other = Person::create(['first_name' => 'Other', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $facility->id]);
    $otherPolicy = ClientInsurancePolicy::create(['person_id' => $other->id, 'payer_name' => 'Protected', 'coverage_order' => 'primary', 'verification_status' => 'pending']);
    $this->delete(route('facility.clients.insurance.destroy', [$enrollment, $otherPolicy]))->assertNotFound();
    $this->delete(route('facility.clients.insurance.destroy', [$enrollment, $policy]))->assertRedirect();
    expect(ClientInsurancePolicy::count())->toBe(1);
});

it('does not expose org-owned insurance through an unassigned facility', function () {
    [$org, , , , $enrollment] = insuranceSetup();
    $otherFacility = Facility::factory()->create();
    $this->withSession([FacilityContext::SESSION_KEY => $otherFacility->id]);
    $this->post(route('facility.clients.insurance.store', $enrollment), ['payer_name' => 'Denied', 'coverage_order' => 'primary', 'verification_status' => 'pending'])->assertForbidden();
    $this->get(route('facility.clients.show', $enrollment))->assertForbidden();
});

it('shares organization coverage across authorized facility enrollments for the same client', function () {
    [$org, $user, $facility, $person, $enrollment] = insuranceSetup();
    $policy = ClientInsurancePolicy::create(['person_id' => $person->id, 'payer_name' => 'Shared payer', 'coverage_order' => 'primary', 'verification_status' => 'pending']);
    $secondFacility = Facility::factory()->create();
    DB::table('organization_staff_facilities')->insert(['organization_id' => $org->id, 'facility_id' => $secondFacility->id, 'user_id' => $user->id, 'role' => 'clinician', 'created_at' => now(), 'updated_at' => now()]);
    $secondEnrollment = ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $secondFacility->id, 'enrolled_at' => today()->toDateString()]);
    $this->withSession([FacilityContext::SESSION_KEY => $secondFacility->id]);
    $this->get(route('facility.clients.show', $secondEnrollment))->assertOk()->assertInertia(fn (Assert $page) => $page->where('insurancePolicies.0.id', $policy->id)->etc());
    $this->get(route('facility.clients.show', $enrollment))->assertNotFound();
});
