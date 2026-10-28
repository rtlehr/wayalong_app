<?php

return [
    // A configurable review threshold, NOT an instruction to delete records.
    // Actual retention periods require approved legal and operational policy.
    'review_after_days' => (int) env('WAYALONG_AUDIT_REVIEW_AFTER_DAYS', 365),
];
