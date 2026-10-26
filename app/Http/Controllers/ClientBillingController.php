<?php

namespace App\Http\Controllers;

use App\Models\ClientBillingCharge;
use App\Models\ClientBillingPayment;
use App\Models\ClientClaimAdjustment;
use App\Models\ClientInsuranceClaim;
use App\Models\ClientInsurancePolicy;
use App\Models\ClientEnrollment;
use App\Support\OrganizationContext;
use App\Support\ClaimReconciliation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClientBillingController extends Controller
{
    protected function authorizedClient(Request $request, ClientEnrollment $enrollment): array
    {
        $organization = app(OrganizationContext::class)->current($request);
        abort_unless($organization !== null, 403);
        $facility = $enrollment->facility;
        $person = $enrollment->person;
        abort_unless($facility && (int) $facility->organization_id === (int) $organization->id
            && $facility->status === 'active' && $person && $person->person_type === 'client'
            && (int) $person->organization_id === (int) $organization->id, 404);

        $membership = $request->user()->organizations()
            ->whereKey($organization->id)->where('organizations.status', 'active')
            ->wherePivot('status', 'active')->first();
        abort_unless($membership && in_array($membership->pivot->role, ['owner', 'admin'], true), 403);
        $assigned = DB::table('organization_staff_facilities')
            ->where('organization_id', $organization->id)
            ->where('facility_id', $facility->id)
            ->where('user_id', $request->user()->id)->exists();
        abort_unless($assigned, 403);

        return [$facility, $person];
    }

    public function index(Request $request, ClientEnrollment $enrollment): Response
    {
        [$facility, $person] = $this->authorizedClient($request, $enrollment);
        $charges = ClientBillingCharge::query()->where('person_id', $person->id)->orderByDesc('id')->get();
        $payments = ClientBillingPayment::query()->where('person_id', $person->id)->orderByDesc('id')->get();
        $adjustments = ClientClaimAdjustment::query()->where('person_id', $person->id)->orderByDesc('id')->get();
        $claims = ClientInsuranceClaim::query()->where('person_id', $person->id)->with('events')->orderByDesc('id')->get();
        $policies = ClientInsurancePolicy::query()->where('person_id', $person->id)->orderBy('id')->get();
        $paidByCharge = $payments->groupBy('charge_id')->map(fn ($entries) => (int) $entries->sum('amount_cents'));
        $adjustedByCharge = $adjustments->groupBy('charge_id')->map(fn ($entries) => (int) $entries->sum('amount_cents'));
        $claimsByCharge = $claims->groupBy('charge_id');
        $chargesById = $charges->keyBy('id');
        $paymentsByCharge = $payments->groupBy('charge_id');
        $adjustmentsByClaim = $adjustments->groupBy('claim_id');
        $posted = $charges->whereNull('reverses_charge_id')->sum('amount_cents');
        $reversed = $charges->whereNotNull('reverses_charge_id')->sum('amount_cents');
        return Inertia::render('facilities/ClientBilling', [
            'enrollment' => $enrollment->only('id'),
            'client' => $person->only('id', 'first_name', 'last_name'),
            'facility' => $facility->only('id', 'name'),
            'charges' => $charges->map(fn (ClientBillingCharge $charge): array => [
                'id' => $charge->id,
                'service_on' => $charge->service_on?->toDateString(),
                'description' => $charge->description,
                'reversal_reason' => $charge->reverses_charge_id !== null ? $charge->notes : null,
                'amount_cents' => $charge->amount_cents,
                'responsibility' => $charge->responsibility,
                'facility_id' => $charge->facility_id,
                'reverses_charge_id' => $charge->reverses_charge_id,
                'is_reversed' => $charges->contains('reverses_charge_id', $charge->id),
                'paid_cents' => (int) ($paidByCharge->get($charge->id) ?? 0),
                'adjusted_cents' => (int) ($adjustedByCharge->get($charge->id) ?? 0),
                'due_cents' => $charge->reverses_charge_id === null && ! $charges->contains('reverses_charge_id', $charge->id)
                    ? $charge->amount_cents - (int) ($paidByCharge->get($charge->id) ?? 0) - (int) ($adjustedByCharge->get($charge->id) ?? 0) : 0,
                'created_at' => $charge->created_at?->toDateTimeString(),
            ])->values(),
            'payments' => $payments->map(fn (ClientBillingPayment $payment): array => [
                'id' => $payment->id, 'charge_id' => $payment->charge_id,
                'received_on' => $payment->received_on?->toDateString(),
                'payer_type' => $payment->payer_type, 'method' => $payment->method,
                'amount_cents' => $payment->amount_cents,
                'reference' => $payment->reference,
                'reversal_reason' => $payment->reverses_payment_id !== null ? $payment->notes : null,
                'reverses_payment_id' => $payment->reverses_payment_id,
                'is_reversed' => $payments->contains('reverses_payment_id', $payment->id),
            ])->values(),
            'adjustments' => $adjustments->map(fn (ClientClaimAdjustment $adjustment): array => [
                'id' => $adjustment->id, 'claim_id' => $adjustment->claim_id,
                'charge_id' => $adjustment->charge_id, 'adjusted_on' => $adjustment->adjusted_on?->toDateString(),
                'kind' => $adjustment->kind, 'amount_cents' => $adjustment->amount_cents,
                'reason' => $adjustment->reason, 'reverses_adjustment_id' => $adjustment->reverses_adjustment_id,
                'is_reversed' => $adjustments->contains('reverses_adjustment_id', $adjustment->id),
            ])->values(),
            'insurancePolicies' => $policies->map(fn (ClientInsurancePolicy $policy): array => [
                'id' => $policy->id, 'name' => $policy->payer_name, 'order' => $policy->coverage_order,
                'expires_on' => $policy->expires_on?->toDateString(),
                'effective_on' => $policy->effective_on?->toDateString(),
                'verification_status' => $policy->verification_status,
            ])->values(),
            'claims' => $claims->map(function (ClientInsuranceClaim $claim) use ($chargesById, $charges, $claimsByCharge, $paymentsByCharge, $adjustmentsByClaim): array {
                $charge = $chargesById->get($claim->charge_id);
                $claimPayments = $paymentsByCharge->get($claim->charge_id, collect());
                $claimAdjustments = $adjustmentsByClaim->get($claim->id, collect());
                $reconciliation = ClaimReconciliation::review(
                    $claim->status,
                    $claim->allowed_amount_cents,
                    $claim->insurer_paid_cents,
                    $claim->patient_responsibility_cents,
                    (int) ($charge?->amount_cents ?? 0),
                    $claimsByCharge->get($claim->charge_id, collect())->count(),
                    $charge === null || $charge->reverses_charge_id !== null || $charges->contains('reverses_charge_id', $claim->charge_id),
                    (int) $claimPayments->where('payer_type', 'insurance')->sum('amount_cents'),
                    (int) $claimPayments->where('payer_type', 'client')->sum('amount_cents'),
                    (int) $claimAdjustments->sum('amount_cents'),
                );
                return [
                    'reconciliation' => $reconciliation,
                'id' => $claim->id, 'charge_id' => $claim->charge_id,
                'policy_id' => $claim->client_insurance_policy_id,
                'claim_number' => $claim->claim_number, 'status' => $claim->status,
                'submitted_on' => $claim->submitted_on?->toDateString(),
                'adjudicated_on' => $claim->adjudicated_on?->toDateString(),
                'allowed_amount_cents' => $claim->allowed_amount_cents,
                'insurer_paid_cents' => $claim->insurer_paid_cents,
                'patient_responsibility_cents' => $claim->patient_responsibility_cents,
                'events' => $claim->events->map(fn ($event): array => [
                    'id' => $event->id, 'status' => $event->status,
                    'notes' => $event->notes, 'created_at' => $event->created_at?->toDateTimeString(),
                ])->values(),
                ];
            })->values(),
            'summary' => [
                'posted_cents' => $posted, 'reversed_cents' => $reversed,
                'net_charges_cents' => $posted + $reversed,
                'net_payments_cents' => (int) $payments->sum('amount_cents'),
                'net_adjustments_cents' => (int) $adjustments->sum('amount_cents'),
                'outstanding_cents' => $posted + $reversed - (int) $payments->sum('amount_cents') - (int) $adjustments->sum('amount_cents'),
            ],
        ]);
    }

    public function store(Request $request, ClientEnrollment $enrollment): RedirectResponse
    {
        [$facility, $person] = $this->authorizedClient($request, $enrollment);
        $data = $request->validate([
            'service_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:240'],
            'amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'responsibility' => ['required', Rule::in(['insurance', 'client'])],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        [$whole, $fraction] = array_pad(explode('.', $data['amount'], 2), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($cents <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => 'Charge amount must be greater than zero.']);
        }
        ClientBillingCharge::create([
            'person_id' => $person->id,
            'facility_id' => $facility->id,
            'created_by' => $request->user()->id,
            'service_on' => $data['service_on'],
            'description' => $data['description'],
            'amount_cents' => $cents,
            'responsibility' => $data['responsibility'],
            'notes' => $data['notes'] ?? null,
        ]);
        return redirect()->route('facility.clients.billing.index', $enrollment)->with('success', 'Charge posted.');
    }

    public function reverse(Request $request, ClientEnrollment $enrollment, ClientBillingCharge $charge): RedirectResponse
    {
        [$facility, $person] = $this->authorizedClient($request, $enrollment);
        abort_unless((int) $charge->person_id === (int) $person->id && $charge->reverses_charge_id === null, 404);
        $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($charge, $request, $facility, $person): void {
            // Lock original row to prevent simultaneous double reversals.
            $original = ClientBillingCharge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();
            abort_if(ClientBillingCharge::query()->where('reverses_charge_id', $original->id)->exists(), 409, 'Already reversed.');
            abort_if(ClientBillingPayment::query()->where('charge_id', $original->id)->sum('amount_cents') !== 0, 409, 'Reverse payments on this charge first.');
            abort_if(ClientClaimAdjustment::query()->where('charge_id', $original->id)->sum('amount_cents') !== 0, 409, 'Reverse claim adjustments on this charge first.');
            ClientBillingCharge::create([
                'person_id' => $person->id,
                'facility_id' => $facility->id,
                'created_by' => $request->user()->id,
                'reversed_by' => $request->user()->id,
                'reverses_charge_id' => $original->id,
                'service_on' => $original->service_on->toDateString(),
                'description' => 'Reversal: '.$original->description,
                'amount_cents' => -$original->amount_cents,
                'responsibility' => $original->responsibility,
                'notes' => $request->input('reason'),
            ]);
        });
        return redirect()->route('facility.clients.billing.index', $enrollment)->with('success', 'Charge reversed. History preserved.');
    }
}
