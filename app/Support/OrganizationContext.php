<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;

class OrganizationContext
{
    public const SESSION_KEY = 'active_organization_id';

    public function current(Request $request): ?Organization
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $organizations = $user->organizations()
            ->where('organizations.status', 'active')
            ->wherePivot('status', 'active');

        $selected = $request->session()->get(self::SESSION_KEY);

        if ($selected !== null) {
            $organization = (clone $organizations)->whereKey($selected)->first();

            if ($organization !== null) {
                return $organization;
            }

            $request->session()->forget([self::SESSION_KEY, FacilityContext::SESSION_KEY]);
        }

        $organization = $organizations->orderBy('organizations.id')->first();

        if ($organization !== null) {
            $request->session()->put(self::SESSION_KEY, $organization->id);
        }

        return $organization;
    }

    public function select(Request $request, int $organizationId): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        $allowed = $user->organizations()
            ->whereKey($organizationId)
            ->where('organizations.status', 'active')
            ->wherePivot('status', 'active')
            ->exists();

        if ($allowed) {
            if ((int) $request->session()->get(self::SESSION_KEY) !== $organizationId) {
                $request->session()->forget(FacilityContext::SESSION_KEY);
            }
            $request->session()->put(self::SESSION_KEY, $organizationId);
        }

        return $allowed;
    }
}
