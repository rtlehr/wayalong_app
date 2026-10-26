<?php

use App\Models\ClientBillingCharge;
use App\Models\ClientBillingPayment;
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
beforeEach(function () { $this->withoutVite(); });

function paymentSetup(string $role = 'owner', bool $assigned = true): array
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user, ['role' => $role, 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $facility = Facility::factory()->create();
    if ($assigned) {
        DB::table('organization_staff_facilities')->insert([
            'organization_id' => $org->id, 'facility_id' => $facility->id,
            'user_id' => $user->id, 'role' => 'clinician', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $person = Person::create(['first_name' => 'Pay', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $facility->id]);
    $enrollment = ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString()]);
    $charge = ClientBillingCharge::create([
        'person_id' => $person->id, 'facility_id' => $facility->id, 'created_by' => $user->id,
        'service_on' => today()->toDateString(), 'description' => 'Service',
        'amount_cents' => 10000, 'responsibility' => 'insurance',
    ]);
    return [$person, $enrollment, $charge];
}

function paymentData(string $amount = '25.35'): array
{
    return ['received_on' => today()->toDateString(), 'amount' => $amount,
        'payer_type' => 'insurance', 'method' => 'insurer_remittance',
        'reference' => 'SECURE-REFERENCE-1234'];
}

it('allocates exact cents to a charge, encrypts references and calculates outstanding', function () {
    [, $enrollment, $charge] = paymentSetup();
    $this->post(route('facility.clients.billing.payments.store', [$enrollment, $charge]), paymentData())->assertRedirect();
    expect(ClientBillingPayment::firstOrFail()->amount_cents)->toBe(2535);
    expect(ClientBillingPayment::firstOrFail()->reference)->toBe('SECURE-REFERENCE-1234');
    expect(DB::table('client_billing_payments')->value('reference'))->not->toContain('SECURE-REFERENCE-1234');
    $this->get(route('facility.clients.billing.index', $enrollment))->assertInertia(fn (Assert $page) => $page
        ->where('summary.net_payments_cents', 2535)->where('summary.outstanding_cents', 7465)
        ->where('charges.0.due_cents', 7465)->has('payments', 1)->etc());
});

it('prevents overpayment and invalid amounts', function () {
    [, $enrollment, $charge] = paymentSetup();
    foreach (['0', '-1', '1.999', '1e4', '100.01'] as $amount) {
        $this->post(route('facility.clients.billing.payments.store', [$enrollment, $charge]), paymentData($amount))
            ->assertSessionHasErrors('amount');
    }
    $this->post(route('facility.clients.billing.payments.store', [$enrollment, $charge]), paymentData('60'))->assertRedirect();
    $this->post(route('facility.clients.billing.payments.store', [$enrollment, $charge]), paymentData('40.01'))
        ->assertSessionHasErrors('amount');
    expect(ClientBillingPayment::count())->toBe(1);
});

it('preserves immutable reversal history and requires payments reversed before charge reversal', function () {
    [, $enrollment, $charge] = paymentSetup();
    $this->post(route('facility.clients.billing.payments.store', [$enrollment, $charge]), paymentData())->assertRedirect();
    $payment = ClientBillingPayment::firstOrFail();
    $this->post(route('facility.clients.billing.charges.reverse', [$enrollment, $charge]), ['reason' => 'Correction'])->assertStatus(409);
    $this->post(route('facility.clients.billing.payments.reverse', [$enrollment, $payment]), ['reason' => 'Bad check'])->assertRedirect();
    expect(ClientBillingPayment::count())->toBe(2);
    expect(ClientBillingPayment::whereNotNull('reverses_payment_id')->firstOrFail()->amount_cents)->toBe(-2535);
    $this->post(route('facility.clients.billing.payments.reverse', [$enrollment, $payment]), ['reason' => 'Repeat'])->assertStatus(409);
    $this->post(route('facility.clients.billing.charges.reverse', [$enrollment, $charge]), ['reason' => 'Correction'])->assertRedirect();
    $this->get(route('facility.clients.billing.index', $enrollment))->assertInertia(fn (Assert $page) => $page
        ->where('summary.outstanding_cents', 0)->has('payments', 2)
        ->where('payments.0.reversal_reason', 'Bad check')
        ->where('payments.1.reversal_reason', null)
        ->where('charges.0.reversal_reason', 'Correction')
        ->etc());
});

it('denies ordinary staff and unassigned administrators from recording payments', function () {
    foreach ([['staff', true], ['admin', false]] as [$role, $assigned]) {
        [, $enrollment, $charge] = paymentSetup($role, $assigned);
        $this->post(route('facility.clients.billing.payments.store', [$enrollment, $charge]), paymentData())->assertForbidden();
    }
});

it('rejects applying payments against another clients charge', function () {
    [$person, $enrollment] = paymentSetup();
    $other = Person::create(['first_name' => 'Other', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $person->facility_id]);
    $otherCharge = ClientBillingCharge::create([
        'person_id' => $other->id, 'facility_id' => $person->facility_id,
        'created_by' => auth()->id(), 'service_on' => today()->toDateString(),
        'description' => 'Other', 'amount_cents' => 5000, 'responsibility' => 'client',
    ]);
    $this->post(route('facility.clients.billing.payments.store', [$enrollment, $otherCharge]), paymentData())->assertNotFound();
});
