<?php

namespace App\Http\Controllers;

use App\Models\ClientBillingCharge;
use App\Models\ClientClaimAdjustment;
use App\Models\ClientBillingPayment;
use App\Models\ClientEnrollment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientBillingPaymentController extends ClientBillingController
{
    public function recordPayment(Request $request, ClientEnrollment $enrollment, ClientBillingCharge $charge): RedirectResponse
    {
        [$facility, $person] = $this->authorizedClient($request, $enrollment);
        abort_unless((int) $charge->person_id === (int) $person->id && $charge->reverses_charge_id === null, 404);
        $data = $request->validate([
            'received_on' => ['required', 'date'],
            'amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'payer_type' => ['required', Rule::in(['insurance', 'client'])],
            'method' => ['required', Rule::in(['cash', 'check', 'card', 'bank_transfer', 'insurer_remittance', 'other'])],
            'reference' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        [$whole, $fraction] = array_pad(explode('.', $data['amount'], 2), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Payment must be greater than zero.']);
        }
        DB::transaction(function () use ($charge, $person, $facility, $request, $data, $cents): void {
            $locked = ClientBillingCharge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();
            abort_if(ClientBillingCharge::query()->where('reverses_charge_id', $locked->id)->exists(), 409, 'Charge has been reversed.');
            $paid = (int) ClientBillingPayment::query()->where('charge_id', $locked->id)->sum('amount_cents');
            $adjusted = (int) ClientClaimAdjustment::query()->where('charge_id', $locked->id)->sum('amount_cents');
            if ($cents > $locked->amount_cents - $paid - $adjusted) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the remaining charge balance.']);
            }
            ClientBillingPayment::create([
                'person_id' => $person->id, 'facility_id' => $facility->id,
                'charge_id' => $locked->id, 'created_by' => $request->user()->id,
                'received_on' => $data['received_on'], 'payer_type' => $data['payer_type'],
                'method' => $data['method'], 'amount_cents' => $cents,
                'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
            ]);
        });
        return redirect()->route('facility.clients.billing.index', $enrollment)->with('success', 'Payment recorded.');
    }

    public function reversePayment(Request $request, ClientEnrollment $enrollment, ClientBillingPayment $payment): RedirectResponse
    {
        [$facility, $person] = $this->authorizedClient($request, $enrollment);
        abort_unless((int) $payment->person_id === (int) $person->id && $payment->reverses_payment_id === null, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($payment, $person, $facility, $request, $data): void {
            $lockedCharge = ClientBillingCharge::query()->whereKey($payment->charge_id)->lockForUpdate()->firstOrFail();
            $original = ClientBillingPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_if(ClientBillingPayment::query()->where('reverses_payment_id', $original->id)->exists(), 409, 'Payment already reversed.');
            ClientBillingPayment::create([
                'person_id' => $person->id, 'facility_id' => $facility->id,
                'charge_id' => $lockedCharge->id, 'created_by' => $request->user()->id,
                'reverses_payment_id' => $original->id,
                'received_on' => today()->toDateString(), 'payer_type' => $original->payer_type,
                'method' => $original->method, 'amount_cents' => -$original->amount_cents,
                'notes' => $data['reason'],
            ]);
        });
        return redirect()->route('facility.clients.billing.index', $enrollment)->with('success', 'Payment reversed; history preserved.');
    }
}
