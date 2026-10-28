<?php

namespace App\Support;

use App\Models\ClientEnrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Metadata-only activity log; never persist sensitive record contents here. */
final class SensitiveAccessAudit
{
    public function record(Request $request, ClientEnrollment $enrollment, string $section, string $outcome = 'viewed'): void
    {
        $organization = app(OrganizationContext::class)->current($request);
        if ($request->user() === null || $organization === null ||
            (int) $enrollment->organization_id !== (int) $organization->id) {
            return;
        }

        DB::table('clinical_access_events')->insert([
            'organization_id' => $organization->id,
            'client_enrollment_id' => $enrollment->id,
            'user_id' => $request->user()->id,
            'section' => $section,
            'outcome' => $outcome,
            'created_at' => now(),
        ]);
    }
}
