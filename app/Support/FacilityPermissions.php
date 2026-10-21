<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Authorization for facility-owned records. An organization-level role alone
 * never grants a clinical permission in an unrelated facility.
 */
final class FacilityPermissions
{
    public const VIEW_STAFF = 'facility.staff.view';
    public const VIEW_CLIENTS = 'facility.clients.view';
    public const MANAGE_CLIENTS = 'facility.clients.manage';

    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'clinician' => [self::VIEW_STAFF, self::VIEW_CLIENTS, self::MANAGE_CLIENTS],
        'staff' => [self::VIEW_STAFF],
    ];

    public function role(?User $user, Facility $facility): ?string
    {
        if ($user === null || $facility->status !== 'active') {
            return null;
        }

        $membership = $user->organizations()
            ->whereKey($facility->organization_id)
            ->where('organizations.status', 'active')
            ->wherePivot('status', 'active')
            ->first();

        if ($membership === null) {
            return null;
        }

        $assignment = DB::table('organization_staff_facilities')
            ->where('organization_id', $facility->organization_id)
            ->where('facility_id', $facility->id)
            ->where('user_id', $user->id)
            ->first(['role']);

        if ($assignment === null) {
            return null;
        }

        // Older assignments have no facility role. They inherit their organization's
        // role until an explicit facility-specific role is assigned.
        return $assignment->role ?: $membership->pivot->role;
    }

    public function allows(?User $user, Facility $facility, string $permission): bool
    {
        $role = $this->role($user, $facility);
        if ($role === null) {
            return false;
        }

        // Administrators manage facilities across their organization, but are not
        // silently given access to clinical records by an organization-wide title.
        if (in_array($role, ['owner', 'admin'], true)) {
            return $permission === self::VIEW_STAFF;
        }

        return in_array($permission, self::ROLE_PERMISSIONS[$role] ?? [], true);
    }
}
