<?php

namespace App\Http\Controllers;

use App\Models\ClientEnrollment;
use App\Support\FacilityContext;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use App\Support\SensitiveAccessAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Records the user's intent to export a currently authorized page of client data. */
final class FacilityClientExportAuditController extends Controller
{
    public function __invoke(Request $request, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions, SensitiveAccessAudit $audit): Response
    {
        $facility = $facilities->current($request, $organizations);
        abort_unless($facility !== null && $permissions->allows($request->user(), $facility, FacilityPermissions::VIEW_CLIENTS), 403);
        $data = $request->validate([
            'enrollment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'enrollment_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);
        $ids = $data['enrollment_ids'];
        $canManage = $permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_CLIENTS);
        $enrollments = ClientEnrollment::query()
            ->where('organization_id', $facility->organization_id)
            ->where('facility_id', $facility->id)
            ->whereIn('id', $ids)
            ->when(! $canManage, fn ($query) => $query->whereExists(fn ($assigned) => $assigned->selectRaw('1')
                ->from('client_staff_assignments')
                ->whereColumn('client_staff_assignments.client_enrollment_id', 'client_enrollments.id')
                ->where('client_staff_assignments.organization_id', $facility->organization_id)
                ->where('client_staff_assignments.user_id', $request->user()->id)))
            ->get();
        abort_unless($enrollments->count() === count($ids), 403);
        foreach ($enrollments as $enrollment) {
            $audit->record($request, $enrollment, 'directory_export', 'exported');
        }

        return response('', 204);
    }
}
