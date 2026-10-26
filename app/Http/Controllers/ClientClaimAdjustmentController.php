<?php

namespace App\Http\Controllers;

use App\Models\ClientBillingCharge;
use App\Models\ClientBillingPayment;
use App\Models\ClientClaimAdjustment;
use App\Models\ClientEnrollment;
use App\Models\ClientInsuranceClaim;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientClaimAdjustmentController extends ClientBillingController
{
    public function recordAdjustment(Request $request, ClientEnrollment $enrollment, ClientInsuranceClaim $claim): RedirectResponse
    {
        [$facility, $person] = $this->authorizedClient($request, $enrollment);
        abort_unless((int) $claim->person_id === (int) $person->id, 404);

        $data = $request->validate([
            'adjusted_on' => ['required', 'date'],
            'kind' => ['required', Rule::in(['contractual', 'payer_writeoff', 'other'])],
            'amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        [$whole, $fraction] = array_pad(explode('.', $data['amount'], 2), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Adjustment must be greater than zero.']);
        }

        DB::transaction(function () use ($claim, $facility, $person, $request, $data, $cents): void {
            $lockedCharge = ClientBillingCharge::query()->whereKey($claim->charge_id)->lockForUpdate()->firstOrFail();
            $lockedClaim = ClientInsuranceClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedClaim->person_id === (int) $person->id && (int) $lockedCharge->person_id === (int) $person->id, 404);
            if (! in_array($lockedClaim->status, ['approved', 'denied', 'closed'], true)) {
                throw ValidationException::withMessages(['claim' => 'Finalize the claim decision before recording a payer adjustment.']);
            }
            abort_if(ClientBillingCharge::query()->where('reverses_charge_id', $lockedCharge->id)->exists(), 409, 'Charge has been reversed.');

            $paid = (int) ClientBillingPayment::query()->where('charge_id', $lockedCharge->id)->sum('amount_cents');
            $adjusted = (int) ClientClaimAdjustment::query()->where('charge_id', $lockedCharge->id)->sum('amount_cents');
            if ($cents > $lockedCharge->amount_cents - $paid - $adjusted) {
                throw ValidationException::withMessages(['amount' => 'Adjustment exceeds the remaining charge balance.']);
            }
            ClientClaimAdjustment::create([
                'person_id' => $person->id, 'facility_id' => $facility->id,
                'claim_id' => $lockedClaim->id, 'charge_id' => $lockedCharge->id,
                'created_by' => $request->user()->id, 'adjusted_on' => $data['adjusted_on'],
                'kind' => $data['kind'], 'amount_cents' => $cents, 'reason' => $data['reason'],
            ]);
        });

        return redirect()->route('facility.clients.billing.index', $enrollment)->with('success', 'Claim adjustment recorded.');
    }

    public function reverseAdjustment(Request $request, ClientEnrollment $enrollment, ClientClaimAdjustment $adjustment): RedirectResponse
    {
        [$facility, $person] = $this->authorizedClient($request, $enrollment);
        abort_unless((int) $adjustment->person_id === (int) $person->id && $adjustment->reverses_adjustment_id === null, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        DB::transaction(function () use ($adjustment, $facility, $person, $request, $data): void {
            ClientBillingCharge::query()->whereKey($adjustment->charge_id)->lockForUpdate()->firstOrFail();
            $original = ClientClaimAdjustment::query()->whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            abort_if(ClientClaimAdjustment::query()->where('reverses_adjustment_id', $original->id)->exists(), 409, 'Adjustment already reversed.');
            ClientClaimAdjustment::create([
                'person_id' => $person->id, 'facility_id' => $facility->id,
                'claim_id' => $original->claim_id, 'charge_id' => $original->charge_id,
                'created_by' => $request->user()->id, 'reverses_adjustment_id' => $original->id,
                'adjusted_on' => today()->toDateString(), 'kind' => $original->kind,
                'amount_cents' => -$original->amount_cents, 'reason' => $data['reason'],
            ]);
        });

        return redirect()->route('facility.clients.billing.index', $enrollment)->with('success', 'Adjustment reversed; history preserved.');
    }
}
