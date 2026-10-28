<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\Organization;
use App\Support\FacilityPermissions;
use App\Support\MemberPermissionOverrides;
use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Permission delegation is separate from authority to consume sensitive records. */
final class MemberPermissionOverrideController extends Controller
{
    private function current(Request $request, OrganizationContext $context): Organization
    {
        $organization = $context->current($request);
        abort_unless($organization !== null && $organization->status === 'active', 403);
        abort_unless($organization->users()->whereKey($request->user()->id)->wherePivot('status', 'active')->exists(), 403);
        return $organization;
    }

    private function assigned(Organization $organization, int $userId, Facility $facility): bool
    {
        return $facility->organization_id === $organization->id
            && $facility->status === 'active'
            && DB::table('organization_staff_facilities')
                ->where('organization_id', $organization->id)
                ->where('facility_id', $facility->id)
                ->where('user_id', $userId)->exists();
    }

    private function canManageOrganization(Request $request, Organization $organization): bool
    {
        $membership = $organization->users()->whereKey($request->user()->id)->first();
        return $membership !== null && $membership->pivot->status === 'active'
            && ($membership->pivot->role === 'owner'
                || app(OrganizationPermissions::class)->allows($request->user(), $organization, OrganizationPermissions::MANAGE_MEMBERS));
    }

    public function update(Request $request, OrganizationContext $context, MemberPermissionOverrides $overrides, int $member): RedirectResponse
    {
        $organization = $this->current($request, $context);
        $target = $organization->users()->whereKey($member)->firstOrFail();
        abort_unless($this->canManageOrganization($request, $organization), 403);
        abort_if($request->user()->id === $target->id, 403, 'Self-assigned overrides are prohibited.');

        $data = $request->validate([
            'permission' => ['required', 'string', Rule::in(OrganizationPermissions::CUSTOM_ROLE_PERMISSIONS)],
            'decision' => ['required', Rule::in(['inherit', 'allow', 'deny'])],
            'facility_id' => ['nullable', 'integer'],
        ]);
        $permission = $data['permission'];
        $isFacilityPermission = str_starts_with($permission, 'facility.');
        $facilityId = $data['facility_id'] ?? null;
        if ($isFacilityPermission) {
            abort_unless(is_int($facilityId), 422, 'Select a facility for this permission.');
            $facility = $organization->facilities()->whereKey($facilityId)->firstOrFail();
            abort_unless($this->assigned($organization, $target->id, $facility), 403);
        } else {
            abort_unless($facilityId === null, 422, 'Organization permissions cannot be scoped to a facility.');
        }
        // Organization Owners can delegate within their organization without obtaining
        // permission to see the underlying clinical records themselves.
        $actorRole = $organization->users()->whereKey($request->user()->id)->firstOrFail()->pivot->role;
        if ($actorRole !== 'owner' && $data['decision'] === 'allow') {
            if ($isFacilityPermission) {
                abort_unless(app(FacilityPermissions::class)->allows($request->user(), $facility, $permission), 403);
            } else {
                abort_unless(app(OrganizationPermissions::class)->allows($request->user(), $organization, $permission), 403);
            }
        }
        $overrides->save($organization->id, $target->id, $facilityId, $request->user()->id, $permission, $data['decision']);
        return back()->with('success', 'Staff permission updated.');
    }

    public function facilityEdit(Request $request, OrganizationContext $context, MemberPermissionOverrides $overrides, int $facility, int $member): Response
    {
        $organization = $this->current($request, $context);
        $location = $organization->facilities()->whereKey($facility)->firstOrFail();
        abort_unless(app(FacilityPermissions::class)->allows($request->user(), $location, FacilityPermissions::MANAGE_STAFF_PERMISSIONS), 403);
        abort_unless(in_array(app(FacilityPermissions::class)->role($request->user(), $location),
            ['owner', 'admin', 'facility_owner', 'facility_admin', 'facility_manager'], true), 403);
        $user = $organization->users()->whereKey($member)->wherePivot('status', 'active')->firstOrFail();
        abort_unless($this->assigned($organization, $user->id, $location), 403);
        abort_if($user->id === $request->user()->id, 403);
        abort_if(in_array($user->pivot->role, ['owner', 'admin'], true), 403);
        abort_if(DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
            ->where('facility_id', $location->id)->where('user_id', $user->id)
            ->whereIn('role', ['facility_manager', 'facility_owner', 'facility_admin'])->exists(), 403);

        $permissions = collect(OrganizationPermissions::permissionGroups())
            ->flatMap(fn (array $group) => $group['permissions'])
            ->filter(fn (array $item) => str_starts_with($item['key'], 'facility.')
                && $item['key'] !== FacilityPermissions::MANAGE_STAFF_PERMISSIONS
                && app(FacilityPermissions::class)->allows($request->user(), $location, $item['key']))
            ->values();
        return Inertia::render('facilities/StaffPermissions', [
            'organization' => $organization->only('id', 'name'),
            'facility' => $location->only('id', 'name'),
            'member' => ['id' => $user->id, 'name' => $user->name],
            'permissions' => $permissions,
            'overrides' => $overrides->saved($organization->id, $user->id, $location->id),
            // Evaluated server-side: includes primary/additional roles and scoped Allow/Deny overrides.
            'effectivePermissions' => $permissions->mapWithKeys(
                fn (array $item) => [$item['key'] => app(FacilityPermissions::class)->allows($user, $location, $item['key'])]
            )->all(),
        ]);
    }

    public function facilityUpdate(Request $request, OrganizationContext $context, MemberPermissionOverrides $overrides, int $facility, int $member): RedirectResponse
    {
        $organization = $this->current($request, $context);
        $location = $organization->facilities()->whereKey($facility)->firstOrFail();
        $facilityPermissions = app(FacilityPermissions::class);
        abort_unless($facilityPermissions->allows($request->user(), $location, FacilityPermissions::MANAGE_STAFF_PERMISSIONS), 403);
        abort_unless(in_array($facilityPermissions->role($request->user(), $location),
            ['owner', 'admin', 'facility_owner', 'facility_admin', 'facility_manager'], true), 403);
        $target = $organization->users()->whereKey($member)->wherePivot('status', 'active')->firstOrFail();
        abort_unless($this->assigned($organization, $target->id, $location), 403);
        abort_if($request->user()->id === $target->id, 403);
        abort_if(in_array($target->pivot->role, ['owner', 'admin'], true), 403);
        abort_if(DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
            ->where('facility_id', $location->id)->where('user_id', $target->id)
            ->whereIn('role', ['facility_manager', 'facility_owner', 'facility_admin'])->exists(), 403);
        $data = $request->validate([
            'permission' => ['required', 'string', Rule::in(OrganizationPermissions::CUSTOM_ROLE_PERMISSIONS)],
            'decision' => ['required', Rule::in(['inherit', 'allow', 'deny'])],
        ]);
        abort_unless(str_starts_with($data['permission'], 'facility.')
            && $data['permission'] !== FacilityPermissions::MANAGE_STAFF_PERMISSIONS, 403);
        // A manager may delegate ONLY capabilities currently held by that manager;
        // this also applies to Deny, preventing other managers' rights being altered.
        abort_unless($facilityPermissions->allows($request->user(), $location, $data['permission']), 403);
        $overrides->save($organization->id, $target->id, $location->id, $request->user()->id, $data['permission'], $data['decision']);
        return back()->with('success', 'Facility permission updated.');
    }
}
