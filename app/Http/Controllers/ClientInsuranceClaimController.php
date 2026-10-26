<?php

namespace App\Http\Controllers;

use App\Models\ClientBillingCharge;
use App\Models\ClientEnrollment;
use App\Models\ClientInsuranceClaim;
use App\Models\ClientInsuranceClaimEvent;
use App\Models\ClientInsurancePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientInsuranceClaimController extends ClientBillingController
{
    public function createClaim(Request $request, ClientEnrollment $enrollment): RedirectResponse
    {
        [$facility, $person] = $this->authorizedClient($request, $enrollment);
        $data = $request->validate([
            'charge_id' => ['required', 'integer'],
            'client_insurance_policy_id' => ['required', 'integer'],
            'claim_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $charge = ClientBillingCharge::query()->whereKey($data['charge_id'])->where('person_id', $person->id)->firstOrFail();
        $policy = ClientInsurancePolicy::query()->whereKey($data['client_insurance_policy_id'])->where('person_id', $person->id)->firstOrFail();
        abort_if($charge->reverses_charge_id !== null || ClientBillingCharge::query()->where('reverses_charge_id', $charge->id)->exists(), 422, 'Cannot claim a reversed charge.');

        DB::transaction(function () use ($data, $facility, $person, $charge, $policy, $request): void {
            $claim = ClientInsuranceClaim::create([
                'person_id' => $person->id,
                'facility_id' => $facility->id,
                'charge_id' => $charge->id,
                'client_insurance_policy_id' => $policy->id,
                'created_by' => $request->user()->id,
                'status' => 'draft',
                'claim_number' => $data['claim_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            ClientInsuranceClaimEvent::create(['claim_id' => $claim->id, 'created_by' => $request->user()->id, 'status' => 'draft', 'notes' => 'Claim created']);
        });

        return redirect()->route('facility.clients.billing.index', $enrollment)->with('success', 'Draft insurance claim created.');
    }

    public function changeStatus(Request $request, ClientEnrollment $enrollment, ClientInsuranceClaim $claim): RedirectResponse
    {
        [, $person] = $this->authorizedClient($request, $enrollment);
        abort_unless((int) $claim->person_id === (int) $person->id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['submitted', 'pending', 'approved', 'denied', 'closed'])],
            'notes' => ['required', 'string', 'max:3000'],
            'submitted_on' => ['nullable', 'date'],
        ]);
        $transitions = [
            'draft' => ['submitted'],
            'submitted' => ['pending', 'approved', 'denied'],
            'pending' => ['approved', 'denied'],
            'approved' => ['closed'],
            'denied' => ['closed'],
            'closed' => [],
        ];

        DB::transaction(function () use ($claim, $data, $request, $transitions): void {
            $locked = ClientInsuranceClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if (! in_array($data['status'], $transitions[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'This claim status transition is not allowed.']);
            }
            $locked->status = $data['status'];
            if ($data['status'] === 'submitted') {
                $locked->submitted_on = $data['submitted_on'] ?? today()->toDateString();
            }
            $locked->save();
            ClientInsuranceClaimEvent::create(['claim_id' => $locked->id, 'created_by' => $request->user()->id, 'status' => $data['status'], 'notes' => $data['notes']]);
        });

        return redirect()->route('facility.clients.billing.index', $enrollment)->with('success', 'Claim status updated.');
    }
    public function recordAdjudication(Request $request, ClientEnrollment $enrollment, ClientInsuranceClaim $claim): RedirectResponse
    {
        [, $person] = $this->authorizedClient($request, $enrollment);
        abort_unless((int) $claim->person_id === (int) $person->id, 404);

        $data = $request->validate([
            'adjudicated_on' => ['required', 'date'],
            'allowed_amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'insurer_paid' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'patient_responsibility' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'notes' => ['required', 'string', 'max:3000'],
        ]);

        $toCents = static function (string $amount): int {
            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
            return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        };
        $allowed = $toCents($data['allowed_amount']);
        $insurerPaid = $toCents($data['insurer_paid']);
        $patientResponsibility = $toCents($data['patient_responsibility']);

        DB::transaction(function () use ($claim, $person, $request, $data, $allowed, $insurerPaid, $patientResponsibility): void {
            $locked = ClientInsuranceClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->person_id === (int) $person->id, 404);
            if (! in_array($locked->status, ['approved', 'denied', 'closed'], true)) {
                throw ValidationException::withMessages(['adjudicated_on' => 'Record the payer decision before entering adjudication details.']);
            }
            $charge = ClientBillingCharge::query()->whereKey($locked->charge_id)->firstOrFail();
            abort_unless((int) $charge->person_id === (int) $person->id, 404);
            if ($allowed > $charge->amount_cents || $insurerPaid + $patientResponsibility > $allowed) {
                throw ValidationException::withMessages(['allowed_amount' => 'Amounts must not exceed the billed charge; insurer paid plus patient responsibility must not exceed the allowed amount.']);
            }

            $locked->update([
                'adjudicated_on' => $data['adjudicated_on'],
                'allowed_amount_cents' => $allowed,
                'insurer_paid_cents' => $insurerPaid,
                'patient_responsibility_cents' => $patientResponsibility,
            ]);
            ClientInsuranceClaimEvent::create([
                'claim_id' => $locked->id,
                'created_by' => $request->user()->id,
                'status' => $locked->status,
                'notes' => 'Adjudication recorded/updated: allowed $'.number_format($allowed / 100, 2)
                    .', insurer paid $'.number_format($insurerPaid / 100, 2)
                    .', patient responsibility $'.number_format($patientResponsibility / 100, 2)
                    .'. Reason: '.$data['notes'],
            ]);
        });

        return redirect()->route('facility.clients.billing.index', $enrollment)->with('success', 'Claim adjudication details recorded. Payments and balances were not changed.');
    }

}
