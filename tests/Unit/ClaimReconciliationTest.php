<?php

use App\Support\ClaimReconciliation;

it('shows exact matches between adjudication, insurer payment, and claim adjustments', function () {
    $review = ClaimReconciliation::review('approved', 10000, 7500, 2500, 12500, 1, false, 7500, 1000, 2500);
    expect($review['status'])->toBe('reconciled')
        ->and($review['issues'])->toBe([])
        ->and($review['patient_balance_cents'])->toBe(1500);
});

it('flags insurer payment and adjustment mismatches without modifying the ledger', function () {
    $review = ClaimReconciliation::review('approved', 10000, 7500, 2500, 12500, 1, false, 5000, 0, 0);
    expect($review['status'])->toBe('review_required')
        ->and($review['issues'])->toHaveCount(2)
        ->and($review['expected_adjustments_cents'])->toBe(2500);
});

it('does not attribute charge payments across multiple claims', function () {
    $review = ClaimReconciliation::review('approved', 10000, 7500, 2500, 12500, 2, false, 7500, 0, 2500);
    expect($review['status'])->toBe('review_required')
        ->and($review['issues'][0])->toContain('Multiple claims');
});

it('defers review before adjudication and flags reversed charges', function () {
    expect(ClaimReconciliation::review('pending', null, null, null, 12500, 1, false, 0, 0, 0)['status'])->toBe('not_ready');
    expect(ClaimReconciliation::review('approved', 10000, 7500, 2500, 12500, 1, true, 7500, 0, 2500)['status'])->toBe('review_required');
});

it('flags excess client payment against the adjudicated patient portion', function () {
    $review = ClaimReconciliation::review('approved', 10000, 7500, 2500, 12500, 1, false, 7500, 3000, 2500);
    expect($review['status'])->toBe('review_required')
        ->and($review['patient_balance_cents'])->toBe(-500);
});
