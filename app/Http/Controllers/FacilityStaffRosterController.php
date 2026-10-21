<?php

namespace App\Http\Controllers;

use App\Support\FacilityContext;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** A facility-scoped read-only directory, distinct from organization staff administration. */
class FacilityStaffRosterController extends Controller
{
    public function __invoke(Request $request, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): Response
    {
        $facility = $facilities->current($request, $organizations);
        abort_unless($facility !== null, 403, 'Select an assigned facility first.');
        abort_unless($permissions->allows($request->user(), $facility, FacilityPermissions::VIEW_STAFF), 403, 'Facility permission required.');

        $staff = DB::table('organization_staff_facilities as assignments')
            ->join('users', 'users.id', '=', 'assignments.user_id')
            ->join('organization_user as members', function ($join) {
                $join->on('members.user_id', '=', 'assignments.user_id')
                    ->on('members.organization_id', '=', 'assignments.organization_id');
            })
            ->where('assignments.organization_id', $facility->organization_id)
            ->where('assignments.facility_id', $facility->id)
            ->where('members.status', 'active')
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->select(['users.id', 'users.name', 'assignments.role'])
            ->get();

        return Inertia::render('facilities/StaffRoster', [
            'facility' => $facility->only('id', 'name'),
            'staff' => $staff,
        ]);
    }
}
