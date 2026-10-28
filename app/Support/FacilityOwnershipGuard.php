<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FacilityOwnershipGuard
{
    public function assertCanDeactivateMember(int $organizationId, int $userId): void
    {
        foreach (DB::table('organization_staff_facilities')
            ->where('organization_id', $organizationId)->where('user_id', $userId)
            ->where('role', 'facility_owner')->get() as $assignment) {
            $this->assertCanRemove($organizationId, (int) $assignment->facility_id,
                (int) $assignment->id, 'facility_owner');
        }
    }

    public function assertCanRemove(int $organizationId, int $facilityId, int $assignmentId, ?string $role): void
    {
        if ($role !== 'facility_owner') {
            return;
        }

        $otherOwners = DB::table('organization_staff_facilities as assignments')
            ->join('organization_user as members', function ($join): void {
                $join->on('members.organization_id', '=', 'assignments.organization_id')
                    ->on('members.user_id', '=', 'assignments.user_id');
            })
            ->join('users', 'users.id', '=', 'assignments.user_id')
            ->where('assignments.organization_id', $organizationId)
            ->where('assignments.facility_id', $facilityId)
            ->where('assignments.role', 'facility_owner')
            ->where('assignments.id', '!=', $assignmentId)
            ->where('members.status', 'active')
            ->where('users.is_active', true)->exists();

        if (! $otherOwners) {
            throw ValidationException::withMessages([
                'role' => 'A facility must retain an active Facility Owner before this assignment can be removed.',
            ]);
        }
    }
}
