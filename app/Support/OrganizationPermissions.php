<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Models\User;

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
        'owner' => [self::MANAGE_ORGANIZATION, self::MANAGE_FACILITIES, self::VIEW_MEMBERS, self::MANAGE_MEMBERS],
        'admin' => [self::MANAGE_ORGANIZATION, self::MANAGE_FACILITIES, self::VIEW_MEMBERS, self::MANAGE_MEMBERS],
        'clinician' => [],
        'floor_staff' => [],
        'client_admin' => [],
        'staff' => [],
    ];

    public const CUSTOM_ROLE_PERMISSIONS = [self::MANAGE_FACILITIES, self::VIEW_MEMBERS, self::VIEW_SECURITY_AUDIT];

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

        if (isset(self::ROLE_PERMISSIONS[$role])) {
            return in_array($permission, self::ROLE_PERMISSIONS[$role], true);
        }
        if (! preg_match('/^custom:([1-9][0-9]*)$/', $role, $matches)
            || ! in_array($permission, self::CUSTOM_ROLE_PERMISSIONS, true)) return false;

        $custom = $organization->customRoles()->whereKey((int) $matches[1])->first();
        return $custom !== null && in_array($permission, $custom->permissions ?? [], true);
    }
}
