<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\OrganizationRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Facility capabilities follow the leadership hierarchy. Organization owners
 * have ordinary clinical access across their organization; administrators
 * have administrative access only unless separately granted a clinical role.
 */
final class FacilityPermissions
{
    public const MANAGE_STAFF_PERMISSIONS = 'facility.staff.permissions.manage';
    public const VIEW_STAFF = 'facility.staff.view';
    public const VIEW_CLIENTS = 'facility.clients.view';
    public const VIEW_CLINICAL_CASELOAD = 'facility.clients.clinical_caseload.view';
    public const MANAGE_CLIENTS = 'facility.clients.manage';
    public const MANAGE_ALERTS = 'facility.alerts.manage';
    public const VIEW_SHIFT_NOTES = 'facility.shift_notes.view';
    public const WRITE_SHIFT_NOTES = 'facility.shift_notes.write';
    public const ACK_SHIFT_NOTES = 'facility.shift_notes.acknowledge';

    /** Ordinary treatment and administration capabilities, not legal-record exemptions. */
    private const OWNER_PERMISSIONS = [
        self::MANAGE_STAFF_PERMISSIONS, self::VIEW_STAFF, self::VIEW_CLIENTS,
        self::VIEW_CLINICAL_CASELOAD, self::MANAGE_CLIENTS, self::MANAGE_ALERTS,
        self::VIEW_SHIFT_NOTES, self::WRITE_SHIFT_NOTES, self::ACK_SHIFT_NOTES,
    ];

    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'clinician' => [self::VIEW_STAFF, self::VIEW_CLIENTS, self::VIEW_CLINICAL_CASELOAD, self::MANAGE_ALERTS, self::VIEW_SHIFT_NOTES, self::WRITE_SHIFT_NOTES, self::ACK_SHIFT_NOTES],
        'client_admin' => [self::VIEW_STAFF, self::VIEW_CLIENTS, self::MANAGE_CLIENTS],
        'floor_staff' => [self::VIEW_CLIENTS, self::VIEW_CLINICAL_CASELOAD, self::VIEW_SHIFT_NOTES, self::WRITE_SHIFT_NOTES, self::ACK_SHIFT_NOTES],
        'staff' => [self::VIEW_STAFF],
        'facility_manager' => [self::VIEW_STAFF, self::MANAGE_STAFF_PERMISSIONS],
        'facility_admin' => [self::VIEW_STAFF, self::VIEW_CLIENTS, self::MANAGE_CLIENTS, self::MANAGE_STAFF_PERMISSIONS],
        'facility_owner' => self::OWNER_PERMISSIONS,
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
            ->first(['id', 'role']);

        // Organization leadership reaches all active facilities in its own
        // organization; facility owners/admins remain restricted to assignments.
        if ($membership->pivot->role === 'owner') {
            return 'owner';
        }
        if ($assignment !== null && $assignment->role === 'facility_owner') {
            return 'facility_owner';
        }
        if ($membership->pivot->role === 'admin') {
            return 'admin';
        }

        if ($assignment === null) {
            return null;
        }

        // Older assignments have no facility role. They inherit their organization's
        // role until an explicit facility-specific role is assigned.
        return $assignment->role ?: $membership->pivot->role;
    }

    public function isOwner(?User $user, Facility $facility): bool
    {
        return in_array($this->role($user, $facility), ['owner', 'facility_owner'], true);
    }

    public function allows(?User $user, Facility $facility, string $permission): bool
    {
        $role = $this->role($user, $facility);
        if ($role === null) {
            return false;
        }

        // User-level grants and custom roles never create administrative leadership.
        // Managing staff permissions requires an active organization/facility leader.
        if ($permission === self::MANAGE_STAFF_PERMISSIONS && ! in_array($role, ['owner', 'admin', 'facility_owner', 'facility_admin', 'facility_manager'], true)) {
            return false;
        }

        // Protected ownership cannot be elevated through individual overrides.
        // Legal-record classification checks still happen at the record boundary.
        if (in_array($role, ['owner', 'facility_owner'], true)) {
            return in_array($permission, self::OWNER_PERMISSIONS, true);
        }

        // User decisions are evaluated only AFTER role() checks active organization
        // membership and explicit facility assignment. They never expand client scope.
        if ($permission !== self::MANAGE_STAFF_PERMISSIONS) {
            $override = app(MemberPermissionOverrides::class)->decision($facility->organization_id, $user->id, $permission, $facility->id);
            if ($override !== null) {
                return $override;
            }
        }

        if ($role === 'facility_admin' && in_array($permission, self::ROLE_PERMISSIONS['facility_admin'], true)) {
            return true;
        }

        if ($role === 'admin') {
            if (in_array($permission, [self::VIEW_STAFF, self::VIEW_CLIENTS, self::MANAGE_CLIENTS, self::MANAGE_STAFF_PERMISSIONS], true)) {
                return true;
            }
            // An organization administrator may also serve as a clinician or
            // nurse in a particular facility, but admin status alone never
            // confers that clinical capability.
            $assignment = DB::table('organization_staff_facilities')
                ->where('organization_id', $facility->organization_id)
                ->where('facility_id', $facility->id)
                ->where('user_id', $user->id)->first(['id', 'role']);
            return $assignment !== null && $this->anyRoleAllows(
                app(FacilityRoleAssignments::class)->roles($assignment->id, $assignment->role),
                $facility, $permission
            );
        }

        $assignment = DB::table('organization_staff_facilities')
            ->where('organization_id', $facility->organization_id)
            ->where('facility_id', $facility->id)
            ->where('user_id', $user->id)->first(['id', 'role']);
        $roles = $assignment !== null
            ? app(FacilityRoleAssignments::class)->roles($assignment->id, $role)
            : [$role];
        return $this->anyRoleAllows($roles, $facility, $permission);
    }

    /** @param list<string> $roles */
    private function anyRoleAllows(array $roles, Facility $facility, string $permission): bool
    {
        foreach ($roles as $role) {
            // Secondary role assignments cannot elevate a user to an Owner or Admin.
            if (in_array($role, ['owner', 'admin', 'facility_owner', 'facility_admin', 'facility_manager'], true)) {
                continue;
            }
            if (preg_match('/^custom:([1-9][0-9]*)$/', $role, $matches)) {
                $custom = OrganizationRole::query()->where('organization_id', $facility->organization_id)
                    ->find((int) $matches[1]);
                if ($custom !== null && in_array($permission, OrganizationPermissions::CUSTOM_ROLE_PERMISSIONS, true)
                    && in_array($permission, $custom->permissions ?? [], true)) {
                    return true;
                }
            } elseif (in_array($permission, self::ROLE_PERMISSIONS[$role] ?? [], true)) {
                return true;
            }
        }
        return false;
    }
}
