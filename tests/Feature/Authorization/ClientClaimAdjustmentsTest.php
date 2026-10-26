<?php

use App\Models\ClientBillingCharge;
use App\Models\ClientBillingPayment;
use App\Models\ClientClaimAdjustment;
use App\Models\ClientEnrollment;
use App\Models\ClientInsuranceClaim;
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

function adjustmentsSetup(string $role = 'owner'): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => $role, 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create();
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id, 'facility_id' => $facility->id,
        'user_id' => $user->id, 'role' => 'clinician', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $person = Person::create(['first_name' => 'Billing', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $facility->id]);
    $enrollment = ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString()]);
    $charge = ClientBillingCharge::create([
        'person_id' => $person->id, 'facility_id' => $facility->id, 'created_by' => $user->id,
        'service_on' => today()->toDateString(), 'description' => 'Treatment', 'amount_cents' => 12500, 'responsibility' => 'insurance',
    ]);
    $policy = ClientInsurancePolicy::create(['person_id' => $person->id, 'payer_name' => 'Example Payer', 'coverage_order' => 'primary']);
    $claim = ClientInsuranceClaim::create([
        'person_id' => $person->id, 'facility_id' => $facility->id, 'charge_id' => $charge->id,
        'client_insurance_policy_id' => $policy->id, 'created_by' => $user->id, 'status' => 'approved',
    ]);
    return [$enrollment, $charge, $claim];
}

it('records an encrypted payer adjustment and recalculates net outstanding charges', function () {
    [$enrollment, $charge, $claim] = adjustmentsSetup();
    $this->post(route('facility.clients.billing.adjustments.store', [$enrollment, $claim]), [
        'adjusted_on' => today()->toDateString(), 'kind' => 'contractual', 'amount' => '25.50', 'reason' => 'Negotiated payer reduction',
    ])->assertRedirect();
    expect(ClientClaimAdjustment::count())->toBe(1);
    expect(DB::table('client_claim_adjustments')->value('reason'))->not->toContain('Negotiated payer reduction');
    $this->get(route('facility.clients.billing.index', $enrollment))->assertInertia(fn (Assert $page) => $page
        ->where('summary.net_adjustments_cents', 2550)->where('summary.outstanding_cents', 9950)
        ->where('charges.0.due_cents', 9950)->where('adjustments.0.reason', 'Negotiated payer reduction')->etc());
});

it('rejects adjustments above outstanding and blocks payments exceeding adjusted balance', function () {
    [$enrollment, $charge, $claim] = adjustmentsSetup();
    $route = route('facility.clients.billing.adjustments.store', [$enrollment, $claim]);
    $this->post($route, ['adjusted_on' => today()->toDateString(), 'kind' => 'contractual', 'amount' => '126.00', 'reason' => 'Too much'])->assertSessionHasErrors('amount');
    $this->post($route, ['adjusted_on' => today()->toDateString(), 'kind' => 'contractual', 'amount' => '100.00', 'reason' => 'Rate adjustment'])->assertRedirect();
    $this->post(route('facility.clients.billing.payments.store', [$enrollment, $charge]), [
        'received_on' => today()->toDateString(), 'amount' => '26.00', 'payer_type' => 'insurance', 'method' => 'check',
    ])->assertSessionHasErrors('amount');
    expect(ClientBillingPayment::count())->toBe(0);
});

it('preserves an adjustment reversal and restores its outstanding balance', function () {
    [$enrollment, $charge, $claim] = adjustmentsSetup();
    $this->post(route('facility.clients.billing.adjustments.store', [$enrollment, $claim]), [
        'adjusted_on' => today()->toDateString(), 'kind' => 'payer_writeoff', 'amount' => '50.00', 'reason' => 'Adjustment decision',
    ])->assertRedirect();
    $adjustment = ClientClaimAdjustment::firstOrFail();
    $route = route('facility.clients.billing.adjustments.reverse', [$enrollment, $adjustment]);
    $this->post($route, ['reason' => 'Payer corrected adjudication'])->assertRedirect();
    $this->post($route, ['reason' => 'Duplicate'])->assertStatus(409);
    expect(ClientClaimAdjustment::count())->toBe(2);
    $this->get(route('facility.clients.billing.index', $enrollment))->assertInertia(fn (Assert $page) => $page
        ->where('summary.net_adjustments_cents', 0)->where('summary.outstanding_cents', 12500)
        ->where('adjustments.0.reason', 'Payer corrected adjudication')->etc());
});

it('rejects adjustment creation for draft claims and clinical staff', function () {
    [$enrollment, $charge, $claim] = adjustmentsSetup();
    $claim->update(['status' => 'draft']);
    $data = ['adjusted_on' => today()->toDateString(), 'kind' => 'contractual', 'amount' => '10.00', 'reason' => 'Premature'];
    $this->post(route('facility.clients.billing.adjustments.store', [$enrollment, $claim]), $data)->assertSessionHasErrors('claim');

    [$otherEnrollment, $otherCharge, $otherClaim] = adjustmentsSetup('staff');
    $this->post(route('facility.clients.billing.adjustments.store', [$otherEnrollment, $otherClaim]), $data)->assertForbidden();
    expect(ClientClaimAdjustment::count())->toBe(0);
});
