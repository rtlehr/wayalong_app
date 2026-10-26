<?php

namespace App\Support;

class ClaimReconciliation
{
    /**
     * Compare a single claim's adjudication with charge-level transactions.
     * No allocation is inferred when multiple claims share the same charge.
     * This is a read-only review, not a posting or automatic adjustment.
     *
     * @return array<string, mixed>
     */
    public static function review(
        string $status,
        ?int $allowedCents,
        ?int $reportedInsurerPaidCents,
        ?int $patientResponsibilityCents,
        int $chargeCents,
        int $claimsOnCharge,
        bool $chargeReversed,
        int $recordedInsurerPaidCents,
        int $recordedClientPaidCents,
        int $recordedAdjustmentCents,
    ): array {
        $base = [
            'status' => 'not_ready',
            'issues' => [],
            'recorded_insurer_paid_cents' => $recordedInsurerPaidCents,
            'recorded_client_paid_cents' => $recordedClientPaidCents,
            'recorded_adjustments_cents' => $recordedAdjustmentCents,
            'expected_adjustments_cents' => null,
            'patient_balance_cents' => null,
        ];

        if ($chargeReversed) {
            return [...$base, 'status' => 'review_required', 'issues' => ['Charge has been reversed; review its claim and ledger history.']];
        }
        if ($claimsOnCharge !== 1) {
            return [...$base, 'status' => 'review_required', 'issues' => ['Multiple claims share this charge; payments cannot yet be attributed to one claim.']];
        }
        if (! in_array($status, ['approved', 'denied', 'closed'], true)
            || $allowedCents === null || $reportedInsurerPaidCents === null || $patientResponsibilityCents === null) {
            return [...$base, 'issues' => ['Record a final claim decision and adjudication figures before reconciling.']];
        }

        $expectedAdjustments = $chargeCents - $allowedCents;
        $patientBalance = $patientResponsibilityCents - $recordedClientPaidCents;
        $issues = [];
        if ($reportedInsurerPaidCents !== $recordedInsurerPaidCents) {
            $issues[] = 'Reported insurer payment does not match recorded insurance payments.';
        }
        if ($expectedAdjustments !== $recordedAdjustmentCents) {
            $issues[] = 'Expected contractual adjustment does not match recorded claim adjustments.';
        }
        if ($patientBalance < 0) {
            $issues[] = 'Client payments exceed the reported patient responsibility.';
        }

        return [
            ...$base,
            'status' => $issues === [] ? 'reconciled' : 'review_required',
            'issues' => $issues,
            'expected_adjustments_cents' => $expectedAdjustments,
            'patient_balance_cents' => $patientBalance,
        ];
    }
}
