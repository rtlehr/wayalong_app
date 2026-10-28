<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Models\User;
use App\Support\FacilityPermissions;

/**
 * Role permissions are always evaluated for one explicit organization.
 * Membership status and organization status are checked on every evaluation.
 */
final class OrganizationPermissions
{
    public const MANAGE_ORGANIZATION = 'organization.manage';
    public const MANAGE_FACILITIES = 'facilities.manage';
    public const VIEW_MEMBERS = 'members.view';
    public const MANAGE_MEMBERS = 'members.manage';
    public const VIEW_SECURITY_AUDIT = 'security.audit.view';

    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'owner' => [self::MANAGE_ORGANIZATION, self::MANAGE_FACILITIES, self::VIEW_MEMBERS, self::MANAGE_MEMBERS, self::VIEW_SECURITY_AUDIT],
        'admin' => [self::MANAGE_ORGANIZATION, self::MANAGE_FACILITIES, self::VIEW_MEMBERS, self::MANAGE_MEMBERS],
        'clinician' => [],
        'floor_staff' => [],
        'client_admin' => [],
        'staff' => [],
        'facility_manager' => [],
    ];

    public const CUSTOM_ROLE_PERMISSIONS = [
        self::MANAGE_FACILITIES, self::VIEW_MEMBERS, self::VIEW_SECURITY_AUDIT,
        FacilityPermissions::VIEW_STAFF, FacilityPermissions::VIEW_CLIENTS,
        FacilityPermissions::VIEW_CLINICAL_CASELOAD, FacilityPermissions::MANAGE_CLIENTS,
        FacilityPermissions::MANAGE_ALERTS, FacilityPermissions::VIEW_SHIFT_NOTES,
        FacilityPermissions::WRITE_SHIFT_NOTES, FacilityPermissions::ACK_SHIFT_NOTES,
    ];

    /** Metadata for the role editor; never use frontend labels for authorization. */
    public static function permissionGroups(): array
    {
        return [
            ['name' => 'Organization', 'permissions' => [
                ['key' => self::MANAGE_FACILITIES, 'label' => 'Manage facilities', 'description' => 'Create and update organization facilities'],
                ['key' => self::VIEW_MEMBERS, 'label' => 'View members', 'description' => 'See organization membership records'],
            ]],
            ['name' => 'Client administration', 'permissions' => [
                ['key' => FacilityPermissions::VIEW_STAFF, 'label' => 'View facility staff', 'description' => 'Only at assigned facilities'],
                ['key' => FacilityPermissions::VIEW_CLIENTS, 'label' => 'View client administration', 'description' => 'Subject to client assignment and access rules'],
                ['key' => FacilityPermissions::MANAGE_CLIENTS, 'label' => 'Manage client administration', 'description' => 'Enrollment and administrative changes at assigned facilities'],
            ]],
            ['name' => 'Treatment and handoffs', 'permissions' => [
                ['key' => FacilityPermissions::VIEW_CLINICAL_CASELOAD, 'label' => 'View clinical caseload', 'description' => 'Assigned clients only'],
                ['key' => FacilityPermissions::MANAGE_ALERTS, 'label' => 'Manage client alerts', 'description' => 'Subject to assigned-client checks'],
                ['key' => FacilityPermissions::VIEW_SHIFT_NOTES, 'label' => 'View shift notes', 'description' => 'Subject to assigned-client checks'],
                ['key' => FacilityPermissions::WRITE_SHIFT_NOTES, 'label' => 'Write shift notes', 'description' => 'Subject to assigned-client checks'],
                ['key' => FacilityPermissions::ACK_SHIFT_NOTES, 'label' => 'Acknowledge handoffs', 'description' => 'Subject to assigned-client checks'],
            ]],
            ['name' => 'Security', 'permissions' => [
                ['key' => self::VIEW_SECURITY_AUDIT, 'label' => 'View security audit', 'description' => 'Sensitive access metadata; explicitly granted'],
            ]],
        ];
    }

    /** Templates seed the editor; permissions are saved as an explicit editable set. */
    public static function roleTemplates(): array
    {
        return [
            ['key' => 'blank', 'label' => 'Start with no permissions', 'permissions' => []],
            ['key' => 'floor_staff', 'label' => 'Floor staff', 'permissions' => [FacilityPermissions::VIEW_CLIENTS, FacilityPermissions::VIEW_CLINICAL_CASELOAD, FacilityPermissions::VIEW_SHIFT_NOTES, FacilityPermissions::WRITE_SHIFT_NOTES, FacilityPermissions::ACK_SHIFT_NOTES]],
            ['key' => 'clinician', 'label' => 'Clinician', 'permissions' => [FacilityPermissions::VIEW_STAFF, FacilityPermissions::VIEW_CLIENTS, FacilityPermissions::VIEW_CLINICAL_CASELOAD, FacilityPermissions::MANAGE_ALERTS, FacilityPermissions::VIEW_SHIFT_NOTES, FacilityPermissions::WRITE_SHIFT_NOTES, FacilityPermissions::ACK_SHIFT_NOTES]],
            ['key' => 'facility_manager', 'label' => 'Facility manager', 'permissions' => [FacilityPermissions::VIEW_STAFF]],
            ['key' => 'client_admin', 'label' => 'Client administrator', 'permissions' => [FacilityPermissions::VIEW_STAFF, FacilityPermissions::VIEW_CLIENTS, FacilityPermissions::MANAGE_CLIENTS]],
            ['key' => 'coordinator', 'label' => 'Organization coordinator', 'permissions' => [self::MANAGE_FACILITIES, self::VIEW_MEMBERS]],
            ['key' => 'audit_reviewer', 'label' => 'Security audit reviewer', 'permissions' => [self::VIEW_SECURITY_AUDIT]],
        ];
    }

    public function validRole(Organization $organization, string $role): bool
    {
        if (array_key_exists($role, self::ROLE_PERMISSIONS)) return true;
        if (! preg_match('/^custom:([1-9][0-9]*)$/', $role, $matches)) return false;
        return $organization->customRoles()->whereKey((int) $matches[1])->exists();
    }

    public function allows(?User $user, Organization $organization, string $permission): bool
    {
        if ($user === null || $organization->status !== 'active') {
            return false;
        }

        $membership = $user->organizations()
            ->whereKey($organization->id)
            ->where('organizations.status', 'active')
            ->wherePivot('status', 'active')
            ->first();

        if ($membership === null) {
            return false;
        }

        $role = $membership->pivot->role;
        // Never allow individual overrides to create membership-management authority.
        if (in_array($permission, self::CUSTOM_ROLE_PERMISSIONS, true)
            && ! str_starts_with($permission, 'facility.')) {
            $override = app(MemberPermissionOverrides::class)->decision($organization->id, $user->id, $permission, null);
            if ($override !== null) {
                return $override;
            }
        }

        if (isset(self::ROLE_PERMISSIONS[$role])) {
            return in_array($permission, self::ROLE_PERMISSIONS[$role], true);
        }
        if (! preg_match('/^custom:([1-9][0-9]*)$/', $role, $matches)
            || ! in_array($permission, self::CUSTOM_ROLE_PERMISSIONS, true)) return false;

        $custom = $organization->customRoles()->whereKey((int) $matches[1])->first();
        return $custom !== null && in_array($permission, $custom->permissions ?? [], true);
    }
}
