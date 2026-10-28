<?php

namespace App\Support;

use App\Models\Facility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Facility workspace selection is subordinate to an active organization membership. */
class FacilityContext
{
    public const SESSION_KEY = 'active_facility_id';

    public function current(Request $request, OrganizationContext $organizations): ?Facility
    {
        $organization = $organizations->current($request);
        $user = $request->user();
        $selected = $request->session()->get(self::SESSION_KEY);

        if ($organization === null || $user === null) {
            // Access may have been revoked while this browser session was open.
            // Never retain a facility selection without an authorized organization.
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        if ($selected === null) {
            return null;
        }

        $facility = Facility::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereKey($selected)
            ->first();

        if ($facility !== null && in_array($organization->users()->whereKey($user->id)->first()?->pivot?->role, ['owner', 'admin'], true)) {
            return $facility;
        }

        if ($facility !== null && DB::table('organization_staff_facilities')
            ->where('organization_id', $organization->id)
            ->where('facility_id', $facility->id)
            ->where('user_id', $user->id)->exists()) {
            return $facility;
        }

        $request->session()->forget(self::SESSION_KEY);

        return null;
    }

    public function select(Request $request, OrganizationContext $organizations, int $facilityId): bool
    {
        $organization = $organizations->current($request);
        $user = $request->user();
        if ($organization === null || $user === null) {
            return false;
        }

        $allowed = DB::table('organization_staff_facilities as assignment')
            ->join('facilities', 'facilities.id', '=', 'assignment.facility_id')
            ->where('assignment.organization_id', $organization->id)
            ->where('assignment.user_id', $user->id)
            ->where('assignment.facility_id', $facilityId)
            ->where('facilities.organization_id', $organization->id)
            ->where('facilities.status', 'active')
            ->exists();

        if (! $allowed) {
            $role = $organization->users()->whereKey($user->id)->first()?->pivot?->role;
            $allowed = in_array($role, ['owner', 'admin'], true)
                && $organization->facilities()->whereKey($facilityId)->where('status', 'active')->exists();
        }

        if ($allowed) {
            $request->session()->put(self::SESSION_KEY, $facilityId);
        }

        return $allowed;
    }
}
