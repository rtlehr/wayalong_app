<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;

/** The single source of truth for the current organization's admin navigation. */
final class AdminToolCatalog
{
    public function __construct(private readonly OrganizationPermissions $permissions) {}

    /** @return list<array{key:string,title:string,description:string,href:string,section:string,keywords:list<string>}> */
    public function forUser(?User $user, Organization $organization): array
    {
        $definitions = [
            ['key' => 'users', 'title' => 'User Management', 'description' => 'Shared user details, contact information and organization facility assignments.', 'href' => '/organization/admin/users', 'section' => 'People & Access', 'keywords' => ['contacts', 'accounts', 'profiles'], 'permission' => OrganizationPermissions::MANAGE_MEMBERS],
            ['key' => 'staff', 'title' => 'Staff Directory', 'description' => 'Search staff, update memberships and invite new team members.', 'href' => '/organization/admin/staff', 'section' => 'People & Access', 'keywords' => ['users', 'employees', 'members', 'invitations', 'onboarding'], 'permission' => OrganizationPermissions::MANAGE_MEMBERS],
            ['key' => 'roles', 'title' => 'Roles & Permissions', 'description' => 'Configure organization-specific roles and permitted actions.', 'href' => '/organization/admin/roles', 'section' => 'People & Access', 'keywords' => ['security', 'rbac', 'custom', 'access'], 'permission' => OrganizationPermissions::MANAGE_ORGANIZATION],
            ['key' => 'facilities', 'title' => 'Facilities & Locations', 'description' => 'Manage treatment facilities, addresses and operational status.', 'href' => '/organization/admin/facilities', 'section' => 'Organization', 'keywords' => ['location', 'sites', 'centers', 'clinic'], 'permission' => OrganizationPermissions::MANAGE_ORGANIZATION],
            ['key' => 'settings', 'title' => 'Organization Settings', 'description' => 'Manage organization name and timezone.', 'href' => '/organization/admin', 'section' => 'Organization', 'keywords' => ['profile', 'configuration', 'timezone'], 'permission' => OrganizationPermissions::MANAGE_ORGANIZATION],
        ];

        $result = [];
        foreach ($definitions as $definition) {
            if ($this->permissions->allows($user, $organization, $definition['permission'])) {
                unset($definition['permission']);
                $result[] = $definition;
            }
        }

        return $result;
    }
}
