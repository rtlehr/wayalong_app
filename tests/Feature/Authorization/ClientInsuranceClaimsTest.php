<?php

use App\Models\ClientBillingCharge;
use App\Models\ClientEnrollment;
use App\Models\ClientInsuranceClaim;
use App\Models\ClientInsuranceClaimEvent;
use App\Models\ClientInsurancePolicy;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);
beforeEach(function () { $this->withoutVite(); });

function claimsSetup(string $role = 'owner', bool $assigned = true): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => $role, 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create();
    if ($assigned) {
        DB::table('organization_staff_facilities')->insert([
            'organization_id' => $organization->id, 'facility_id' => $facility->id,
            'user_id' => $user->id, 'role' => 'clinician', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $person = Person::create(['first_name' => 'Claims', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $facility->id]);
    $enrollment = ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString()]);
    $charge = ClientBillingCharge::create([
        'person_id' => $person->id, 'facility_id' => $facility->id, 'created_by' => $user->id,
        'service_on' => today()->toDateString(), 'description' => 'Treatment', 'amount_cents' => 12500, 'responsibility' => 'insurance',
    ]);
    $policy = ClientInsurancePolicy::create(['person_id' => $person->id, 'payer_name' => 'Example insurer', 'coverage_order' => 'primary']);

    return [$enrollment, $charge, $policy, $person];
}

it('creates a draft claim and tracks immutable status events without changing the account balance', function () {
    [$enrollment, $charge, $policy] = claimsSetup();
    $this->post(route('facility.clients.billing.claims.store', $enrollment), [
        'charge_id' => $charge->id, 'client_insurance_policy_id' => $policy->id, 'claim_number' => 'TEST-123', 'notes' => 'Private claim notes',
    ])->assertRedirect();
    $claim = ClientInsuranceClaim::firstOrFail();
    expect($claim->status)->toBe('draft');
    expect(ClientInsuranceClaimEvent::count())->toBe(1);
    expect(DB::table('client_insurance_claims')->value('notes'))->not->toContain('Private claim notes');
    $this->post(route('facility.clients.billing.claims.status', [$enrollment, $claim]), [
        'status' => 'submitted', 'notes' => 'Sent manually',
    ])->assertRedirect();
    $this->post(route('facility.clients.billing.claims.status', [$enrollment, $claim]), [
        'status' => 'pending', 'notes' => 'Payer reviewing',
    ])->assertRedirect();
    expect($claim->fresh()->status)->toBe('pending');
    expect(ClientInsuranceClaimEvent::count())->toBe(3);
    $this->get(route('facility.clients.billing.index', $enrollment))->assertInertia(fn (Assert $page) => $page
        ->has('claims', 1)->where('claims.0.status', 'pending')->has('claims.0.events', 3)
        ->where('claims.0.events.2.notes', 'Payer reviewing')->where('summary.outstanding_cents', 12500)->etc());
});

it('rejects illegal status transitions', function () {
    [$enrollment, $charge, $policy] = claimsSetup();
    $this->post(route('facility.clients.billing.claims.store', $enrollment), [
        'charge_id' => $charge->id, 'client_insurance_policy_id' => $policy->id,
    ])->assertRedirect();
    $claim = ClientInsuranceClaim::firstOrFail();
    $this->post(route('facility.clients.billing.claims.status', [$enrollment, $claim]), [
        'status' => 'approved', 'notes' => 'Skipped submission',
    ])->assertSessionHasErrors('status');
    expect($claim->fresh()->status)->toBe('draft');
    expect(ClientInsuranceClaimEvent::count())->toBe(1);
});

it('denies staff and unassigned administrators access to financial claims', function () {
    foreach ([['staff', true], ['admin', false]] as [$role, $assigned]) {
        [$enrollment, $charge, $policy] = claimsSetup($role, $assigned);
        $this->post(route('facility.clients.billing.claims.store', $enrollment), [
            'charge_id' => $charge->id, 'client_insurance_policy_id' => $policy->id,
        ])->assertForbidden();
    }
});

it('rejects claims against another client policy', function () {
    [$enrollment, $charge] = claimsSetup();
    $other = Person::create(['first_name' => 'Different', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $charge->facility_id]);
    $policy = ClientInsurancePolicy::create(['person_id' => $other->id, 'payer_name' => 'Other payer', 'coverage_order' => 'primary']);
    $this->post(route('facility.clients.billing.claims.store', $enrollment), [
        'charge_id' => $charge->id, 'client_insurance_policy_id' => $policy->id,
    ])->assertNotFound();
});

it('records claim adjudication without double counting payments or adjustments', function () {
    [$enrollment, $charge, $policy] = claimsSetup();
    $claim = ClientInsuranceClaim::create([
        'person_id' => $charge->person_id, 'facility_id' => $charge->facility_id,
        'charge_id' => $charge->id, 'client_insurance_policy_id' => $policy->id,
        'created_by' => auth()->id(), 'status' => 'approved',
    ]);
    $route = route('facility.clients.billing.claims.adjudication', [$enrollment, $claim]);
    $this->post($route, [
        'adjudicated_on' => today()->toDateString(), 'allowed_amount' => '100.00',
        'insurer_paid' => '75.00', 'patient_responsibility' => '25.00', 'notes' => 'Payer EOB reviewed',
    ])->assertRedirect();
    expect($claim->fresh()->allowed_amount_cents)->toBe(10000);
    expect(ClientInsuranceClaimEvent::count())->toBe(1);
    expect(DB::table('client_insurance_claim_events')->value('notes'))->not->toContain('Payer EOB reviewed');
    $this->get(route('facility.clients.billing.index', $enrollment))->assertInertia(fn (Assert $page) => $page
        ->where('claims.0.allowed_amount_cents', 10000)
        ->where('claims.0.patient_responsibility_cents', 2500)
        ->where('summary.outstanding_cents', 12500)->etc());
    $this->post($route, [
        'adjudicated_on' => today()->toDateString(), 'allowed_amount' => '90.00',
        'insurer_paid' => '65.00', 'patient_responsibility' => '25.00', 'notes' => 'Corrected EOB',
    ])->assertRedirect();
    expect(ClientInsuranceClaimEvent::count())->toBe(2);
    expect($claim->fresh()->allowed_amount_cents)->toBe(9000);
});

it('rejects adjudication before a payer decision or with inconsistent amounts', function () {
    [$enrollment, $charge, $policy] = claimsSetup();
    $claim = ClientInsuranceClaim::create([
        'person_id' => $charge->person_id, 'facility_id' => $charge->facility_id,
        'charge_id' => $charge->id, 'client_insurance_policy_id' => $policy->id,
        'created_by' => auth()->id(), 'status' => 'draft',
    ]);
    $route = route('facility.clients.billing.claims.adjudication', [$enrollment, $claim]);
    $data = ['adjudicated_on' => today()->toDateString(), 'allowed_amount' => '100.00',
        'insurer_paid' => '75.00', 'patient_responsibility' => '30.00', 'notes' => 'Test'];
    $this->post($route, $data)->assertSessionHasErrors('adjudicated_on');
    $claim->update(['status' => 'approved']);
    $this->post($route, $data)->assertSessionHasErrors('allowed_amount');
    $this->post($route, [...$data, 'allowed_amount' => '150.00', 'patient_responsibility' => '25.00'])
        ->assertSessionHasErrors('allowed_amount');
    expect(ClientInsuranceClaimEvent::count())->toBe(0);
});

it('blocks unauthorized staff from saving adjudication details', function () {
    [$enrollment, $charge, $policy] = claimsSetup('staff');
    $claim = ClientInsuranceClaim::create([
        'person_id' => $charge->person_id, 'facility_id' => $charge->facility_id,
        'charge_id' => $charge->id, 'client_insurance_policy_id' => $policy->id,
        'created_by' => auth()->id(), 'status' => 'approved',
    ]);
    $this->post(route('facility.clients.billing.claims.adjudication', [$enrollment, $claim]), [
        'adjudicated_on' => today()->toDateString(), 'allowed_amount' => '100.00',
        'insurer_paid' => '75.00', 'patient_responsibility' => '25.00', 'notes' => 'Not allowed',
    ])->assertForbidden();
});


it('exposes a read-only claim reconciliation review on the billing screen', function () {
    [$enrollment, $charge, $policy] = claimsSetup();
    $claim = ClientInsuranceClaim::create([
        'person_id' => $charge->person_id, 'facility_id' => $charge->facility_id,
        'charge_id' => $charge->id, 'client_insurance_policy_id' => $policy->id,
        'created_by' => auth()->id(), 'status' => 'approved',
        'allowed_amount_cents' => 10000, 'insurer_paid_cents' => 7500,
        'patient_responsibility_cents' => 2500, 'adjudicated_on' => today()->toDateString(),
    ]);
    $this->get(route('facility.clients.billing.index', $enrollment))->assertInertia(fn (Assert $page) => $page
        ->where('claims.0.id', $claim->id)
        ->where('claims.0.reconciliation.status', 'review_required')
        ->where('claims.0.reconciliation.recorded_insurer_paid_cents', 0)
        ->where('claims.0.reconciliation.expected_adjustments_cents', 2500)
        ->has('claims.0.reconciliation.issues', 2)
        ->where('summary.outstanding_cents', 12500)->etc());
});
