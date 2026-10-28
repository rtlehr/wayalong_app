<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Additional ordinary facility roles, never a source of ownership authority. */
final class FacilityRoleAssignments
{
    public const ADDITIONAL_ROLES = ['clinician', 'floor_staff', 'client_admin', 'staff'];

    /** @return list<string> */
    public function additional(int $assignmentId): array
    {
        return DB::table('facility_assignment_roles')->where('assignment_id', $assignmentId)->pluck('role')->all();
    }

    /** @return list<string> */
    public function roles(int $assignmentId, ?string $primary): array
    {
        return array_values(array_unique(array_filter([$primary, ...$this->additional($assignmentId)])));
    }
}
