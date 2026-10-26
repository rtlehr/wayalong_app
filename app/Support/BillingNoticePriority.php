<?php

namespace App\Support;

use App\Models\ClientBillingCharge;
use App\Models\ClientBillingPayment;
use App\Models\ClientClaimAdjustment;
use App\Models\ClientInsuranceClaim;
use App\Models\ClientInsurancePolicy;
use Illuminate\Support\Collection;

/** Highest-priority billing notice for each client, matching the Billing Overview rules. */
final class BillingNoticePriority
{
    /** @param Collection<int, int> $personIds
     *  @return array<int, array{level: string, label: string}>
     */
    public static function forPeople(Collection $personIds): array
    {
        $ids = $personIds->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $policies = ClientInsurancePolicy::query()->whereIn('person_id', $ids)->get()->groupBy('person_id');
        $claims = ClientInsuranceClaim::query()->whereIn('person_id', $ids)->get()->groupBy('person_id');
        $charges = ClientBillingCharge::query()->whereIn('person_id', $ids)->get()->groupBy('person_id');
        $payments = ClientBillingPayment::query()->whereIn('person_id', $ids)->get()->groupBy('person_id');
        $adjustments = ClientClaimAdjustment::query()->whereIn('person_id', $ids)->get()->groupBy('person_id');
        $results = [];

        foreach ($ids as $personId) {
            $personPolicies = $policies->get($personId, collect());
            $personClaims = $claims->get($personId, collect());
            $personCharges = $charges->get($personId, collect());
            $personPayments = $payments->get($personId, collect());
            $personAdjustments = $adjustments->get($personId, collect());
            $chargeById = $personCharges->keyBy('id');
            $claimsByCharge = $personClaims->groupBy('charge_id');
            $paymentsByCharge = $personPayments->groupBy('charge_id');
            $adjustmentsByClaim = $personAdjustments->groupBy('claim_id');

            $highest = null;
            $add = static function (string $level, string $label) use (&$highest): void {
                $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
                if ($highest === null || $rank[$level] < $rank[$highest['level']]) {
                    $highest = ['level' => $level, 'label' => $label];
                }
            };

            foreach ($personPolicies as $policy) {
                if ($policy->expires_on && $policy->expires_on->isBefore(today())) {
                    $add('critical', 'Insurance coverage expired');
                } elseif ($policy->expires_on && today()->diffInDays($policy->expires_on, false) <= 30) {
                    $add('warning', 'Insurance coverage expires soon');
                }
                if (in_array($policy->verification_status, ['rejected', 'failed'], true)) {
                    $add('warning', 'Insurance verification needs attention');
                }
            }

            foreach ($personClaims as $claim) {
                $charge = $chargeById->get($claim->charge_id);
                $claimPayments = $paymentsByCharge->get($claim->charge_id, collect());
                $claimAdjustments = $adjustmentsByClaim->get($claim->id, collect());
                $review = ClaimReconciliation::review(
                    $claim->status,
                    $claim->allowed_amount_cents,
                    $claim->insurer_paid_cents,
                    $claim->patient_responsibility_cents,
                    (int) ($charge?->amount_cents ?? 0),
                    $claimsByCharge->get($claim->charge_id, collect())->count(),
                    $charge === null || $charge->reverses_charge_id !== null || $personCharges->contains('reverses_charge_id', $claim->charge_id),
                    (int) $claimPayments->where('payer_type', 'insurance')->sum('amount_cents'),
                    (int) $claimPayments->where('payer_type', 'client')->sum('amount_cents'),
                    (int) $claimAdjustments->sum('amount_cents'),
                );
                if ($review['status'] === 'review_required') {
                    $add('critical', 'Insurance claim reconciliation needs review');
                } elseif ($review['status'] === 'not_ready' && in_array($claim->status, ['approved', 'denied', 'closed'], true)) {
                    $add('warning', 'Claim adjudication incomplete');
                }
                if ($claim->status === 'denied') {
                    $add('warning', 'Insurance claim denied');
                } elseif (in_array($claim->status, ['pending', 'submitted'], true)) {
                    $add('info', 'Insurance claim awaiting decision');
                }
            }

            $outstanding = (int) $personCharges->sum('amount_cents')
                - (int) $personPayments->sum('amount_cents')
                - (int) $personAdjustments->sum('amount_cents');
            if ($outstanding > 0) {
                $add('info', 'Outstanding billing balance');
            }
            if ($highest !== null) {
                $results[(int) $personId] = $highest;
            }
        }

        return $results;
    }
}
