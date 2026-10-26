<?php

namespace App\Http\Controllers;

use App\Models\ClientEnrollment;
use App\Support\OrganizationContext;
use App\Support\BillingNoticePriority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class OrganizationBillingDirectoryController extends Controller
{
    public function __invoke(Request $request, OrganizationContext $context): Response
    {
        $organization = $context->current($request);
        abort_unless($organization !== null, 403);
        $membership = $request->user()->organizations()
            ->whereKey($organization->id)->where('organizations.status', 'active')
            ->wherePivot('status', 'active')->first();
        abort_unless($membership && in_array($membership->pivot->role, ['owner', 'admin'], true), 403);

        $facilityIds = DB::table('organization_staff_facilities as assignments')
            ->join('facilities', 'facilities.id', '=', 'assignments.facility_id')
            ->where('assignments.organization_id', $organization->id)
            ->where('assignments.user_id', $request->user()->id)
            ->where('facilities.organization_id', $organization->id)
            ->where('facilities.status', 'active')
            ->pluck('assignments.facility_id');

        $enrollments = ClientEnrollment::query()
            ->with(['person', 'facility'])
            ->whereIn('facility_id', $facilityIds)
            ->whereHas('person', fn ($query) => $query->where('person_type', 'client'))
            ->orderByDesc('id')->get();

        $notices = BillingNoticePriority::forPeople($enrollments->pluck('person_id'));

        return Inertia::render('admin/ClientBillingDirectory', [
            'clients' => $enrollments->map(fn (ClientEnrollment $enrollment): array => [
                'enrollment_id' => $enrollment->id,
                'client_name' => trim($enrollment->person->first_name.' '.$enrollment->person->last_name),
                'facility_name' => $enrollment->facility->name,
                'status' => $enrollment->status,
                'notice' => $notices[(int) $enrollment->person_id] ?? null,
            ])->values(),
            'hasFacilityAssignments' => $facilityIds->isNotEmpty(),
        ]);
    }
}
