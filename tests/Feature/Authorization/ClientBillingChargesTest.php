<?php

use App\Models\ClientBillingCharge;
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
beforeEach(function () { $this->withoutVite(); });

function billingSetup(string $orgRole = 'admin', bool $assigned = true): array
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user, ['role' => $orgRole, 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $facility = Facility::factory()->create();
    if ($assigned) {
        DB::table('organization_staff_facilities')->insert([
            'organization_id' => $org->id, 'facility_id' => $facility->id,
            'user_id' => $user->id, 'role' => 'clinician', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    test()->withSession([FacilityContext::SESSION_KEY => $facility->id]);
    $person = Person::create(['first_name' => 'Billing', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $facility->id]);
    $enrollment = ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString()]);
    return [$org, $user, $facility, $person, $enrollment];
}

it('posts exact cent charges, shows totals and keeps encrypted notes', function () {
    [, , , $person, $enrollment] = billingSetup();
    $this->post(route('facility.clients.billing.charges.store', $enrollment), [
        'service_on' => today()->toDateString(), 'description' => 'Service session',
        'amount' => '129.95', 'responsibility' => 'insurance', 'notes' => 'PRIVATE BILLING NOTE',
    ])->assertRedirect();
    $charge = ClientBillingCharge::firstOrFail();
    expect($charge->amount_cents)->toBe(12995)->and($charge->person_id)->toBe($person->id)
        ->and($charge->notes)->toBe('PRIVATE BILLING NOTE');
    expect(DB::table('client_billing_charges')->value('notes'))->not->toContain('PRIVATE BILLING NOTE');
    $this->get(route('facility.clients.billing.index', $enrollment))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('facilities/ClientBilling')->where('summary.net_charges_cents', 12995)->etc());
});

it('reverses a charge exactly once without deleting original financial history', function () {
    [, , , , $enrollment] = billingSetup();
    $this->post(route('facility.clients.billing.charges.store', $enrollment), [
        'service_on' => today()->toDateString(), 'description' => 'Service', 'amount' => '19.99', 'responsibility' => 'client',
    ])->assertRedirect();
    $charge = ClientBillingCharge::firstOrFail();
    $this->post(route('facility.clients.billing.charges.reverse', [$enrollment, $charge]), ['reason' => 'Entered in error'])->assertRedirect();
    expect(ClientBillingCharge::count())->toBe(2)->and(ClientBillingCharge::whereNotNull('reverses_charge_id')->firstOrFail()->amount_cents)->toBe(-1999);
    $this->get(route('facility.clients.billing.index', $enrollment))->assertInertia(fn (Assert $page) => $page
        ->where('summary.net_charges_cents', 0)
        ->where('charges.0.reversal_reason', 'Entered in error')
        ->where('charges.1.reversal_reason', null)
        ->etc());
    $this->post(route('facility.clients.billing.charges.reverse', [$enrollment, $charge]), ['reason' => 'Again'])->assertStatus(409);
    expect(ClientBillingCharge::count())->toBe(2);
});

it('rejects non-admin clinical staff and unassigned finance administrators', function () {
    [, , , , $enrollment] = billingSetup('staff');
    $this->get(route('facility.clients.billing.index', $enrollment))->assertForbidden();
    $this->post(route('facility.clients.billing.charges.store', $enrollment), [])->assertForbidden();
});

it('validates cents and blocks billing on another client', function () {
    [, , $facility, $person, $enrollment] = billingSetup();
    foreach (['0', '-1', '1.999', '1e5'] as $invalid) {
        $this->post(route('facility.clients.billing.charges.store', $enrollment), [
            'service_on' => today()->toDateString(), 'description' => 'Service', 'amount' => $invalid, 'responsibility' => 'client',
        ])->assertSessionHasErrors('amount');
    }
    expect(ClientBillingCharge::count())->toBe(0);
    $other = Person::create(['first_name' => 'Other', 'last_name' => 'Person', 'person_type' => 'client', 'facility_id' => $facility->id]);
    $charge = ClientBillingCharge::create([
        'person_id' => $other->id, 'facility_id' => $facility->id, 'created_by' => auth()->id(),
        'service_on' => today()->toDateString(), 'description' => 'Other charge', 'amount_cents' => 500,
        'responsibility' => 'client',
    ]);
    $this->post(route('facility.clients.billing.charges.reverse', [$enrollment, $charge]), ['reason' => 'No'])->assertNotFound();
});

it('denies an organization administrator without explicit facility assignment', function () {
    [, , , , $enrollment] = billingSetup('admin', false);
    $this->get(route('facility.clients.billing.index', $enrollment))->assertForbidden();
});

it('shows the finance directory to an assigned organization owner without requiring a selected facility', function () {
    [, , , , $enrollment] = billingSetup('owner');
    $this->withSession([FacilityContext::SESSION_KEY => null]);
    $this->get(route('organization.admin.billing.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/ClientBillingDirectory')
            ->has('clients', 1)->where('clients.0.enrollment_id', $enrollment->id)->etc());
    $this->get(route('facility.clients.billing.index', $enrollment))->assertOk();
    $this->get(route('facility.clients.index'))->assertForbidden();
});

it('does not expose billing records from unassigned facilities in the organization directory', function () {
    [$organization, , , , $enrollment] = billingSetup('owner');
    $otherFacility = Facility::factory()->create();
    $otherPerson = Person::create(['first_name' => 'Hidden', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $otherFacility->id]);
    $otherEnrollment = ClientEnrollment::create(['person_id' => $otherPerson->id, 'facility_id' => $otherFacility->id, 'enrolled_at' => today()->toDateString()]);
    $this->get(route('organization.admin.billing.index'))->assertInertia(fn (Assert $page) => $page
        ->has('clients', 1)->where('clients.0.enrollment_id', $enrollment->id)->etc());
    $this->get(route('facility.clients.billing.index', $otherEnrollment))->assertForbidden();
});

it('keeps the finance directory unavailable to non-admin staff', function () {
    billingSetup('staff');
    $this->get(route('organization.admin.billing.index'))->assertForbidden();
});

it('shows the highest billing notice priority beside each authorized client in the directory', function () {
    [, , $facility, $person, $enrollment] = billingSetup('owner');

    $this->get(route('organization.admin.billing.index'))->assertInertia(fn (Assert $page) => $page
        ->where('clients.0.notice', null)->etc());

    ClientBillingCharge::create([
        'person_id' => $person->id, 'facility_id' => $facility->id,
        'created_by' => auth()->id(), 'service_on' => today()->toDateString(),
        'description' => 'Open balance', 'amount_cents' => 2500, 'responsibility' => 'client',
    ]);
    $this->get(route('organization.admin.billing.index'))->assertInertia(fn (Assert $page) => $page
        ->where('clients.0.notice.level', 'info')->etc());

    \App\Models\ClientInsurancePolicy::create([
        'person_id' => $person->id, 'payer_name' => 'Sample payer', 'coverage_order' => 'primary',
        'verification_status' => 'unverified', 'expires_on' => today()->addDays(10)->toDateString(),
    ]);
    $this->get(route('organization.admin.billing.index'))->assertInertia(fn (Assert $page) => $page
        ->where('clients.0.notice.level', 'warning')->etc());

    \App\Models\ClientInsurancePolicy::query()->where('person_id', $person->id)
        ->update(['expires_on' => today()->subDay()->toDateString()]);
    $this->get(route('organization.admin.billing.index'))->assertInertia(fn (Assert $page) => $page
        ->where('clients.0.notice.level', 'critical')
        ->where('clients.0.notice.label', 'Insurance coverage expired')->etc());
});

it('does not include notices for clients in facilities a finance admin cannot access', function () {
    [, , , , $enrollment] = billingSetup('owner');
    $otherFacility = Facility::factory()->create();
    $otherPerson = Person::create([
        'first_name' => 'Restricted', 'last_name' => 'Notice',
        'person_type' => 'client', 'facility_id' => $otherFacility->id,
    ]);
    ClientEnrollment::create([
        'person_id' => $otherPerson->id, 'facility_id' => $otherFacility->id,
        'enrolled_at' => today()->toDateString(),
    ]);
    \App\Models\ClientInsurancePolicy::create([
        'person_id' => $otherPerson->id, 'payer_name' => 'Private payer',
        'coverage_order' => 'primary', 'expires_on' => today()->subDay()->toDateString(),
    ]);
    $this->get(route('organization.admin.billing.index'))->assertInertia(fn (Assert $page) => $page
        ->has('clients', 1)
        ->where('clients.0.enrollment_id', $enrollment->id)
        ->where('clients.0.notice', null)
        ->etc());
});
