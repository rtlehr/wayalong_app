<?php

namespace App\Http\Controllers;

use App\Models\OrganizationUserProfile;
use App\Support\FacilityContext;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** A facility-scoped read-only directory, distinct from organization staff administration. */
class FacilityStaffRosterController extends Controller
{
    public function __invoke(Request $request, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): Response
    {
        $facility = $facilities->current($request, $organizations);
        abort_unless($facility !== null, 403, 'Select an assigned facility first.');
        abort_unless($permissions->allows($request->user(), $facility, FacilityPermissions::VIEW_STAFF), 403, 'Facility permission required.');

        $staff = DB::table('organization_staff_facilities as assignments')
            ->join('users', 'users.id', '=', 'assignments.user_id')
            ->join('organization_user as members', function ($join) {
                $join->on('members.user_id', '=', 'assignments.user_id')
                    ->on('members.organization_id', '=', 'assignments.organization_id');
            })
            ->where('assignments.organization_id', $facility->organization_id)
            ->where('assignments.facility_id', $facility->id)
            ->where('members.status', 'active')
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->select(['users.id', 'users.name', 'users.email', 'assignments.role', 'members.role as organization_role'])
            ->get();

        // Contact data belongs to the selected organization, never to another membership.
        // Use the ContactMethod model so encrypted values are decrypted by its casts.
        $profiles = OrganizationUserProfile::query()
            ->where('organization_id', $facility->organization_id)
            ->whereIn('user_id', $staff->pluck('id'))
            ->with(['contactMethods' => fn ($query) => $query->whereIn('kind', ['email', 'phone'])
                ->orderByDesc('is_primary')->orderBy('id')])
            ->get()->keyBy('user_id');

        $activeOwnerCount = DB::table('organization_staff_facilities as assignments')
            ->join('organization_user as members', function ($join): void {
                $join->on('members.organization_id', '=', 'assignments.organization_id')
                    ->on('members.user_id', '=', 'assignments.user_id');
            })
            ->join('users', 'users.id', '=', 'assignments.user_id')
            ->where('assignments.organization_id', $facility->organization_id)
            ->where('assignments.facility_id', $facility->id)
            ->where('assignments.role', 'facility_owner')
            ->where('members.status', 'active')
            ->where('users.is_active', true)
            ->count();

        $organization = $organizations->current($request);
        $actorMembership = $organization?->users()->whereKey($request->user()->id)->first();
        $actorOrgRole = $actorMembership?->pivot?->role;
        $orgLeadership = in_array($actorOrgRole, ['owner', 'admin'], true);
        $actorIsOrganizationOwner = $actorOrgRole === 'owner';
        $actorIsFacilityOwner = DB::table('organization_staff_facilities')
            ->where('organization_id', $facility->organization_id)
            ->where('facility_id', $facility->id)
            ->where('user_id', $request->user()->id)
            ->where('role', 'facility_owner')->exists();
        $actorIsFacilityAdmin = DB::table('organization_staff_facilities')
            ->where('organization_id', $facility->organization_id)
            ->where('facility_id', $facility->id)
            ->where('user_id', $request->user()->id)
            ->where('role', 'facility_admin')->exists();
        $actorCanManagePermissions = $permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_STAFF_PERMISSIONS);
        // Mirror the target restrictions in MemberPermissionOverrideController::facilityEdit
        // and FacilityRoleAssignmentController::facilityEdit. A roster link must never
        // advertise an action whose edit screen will reject the user.
        $protectedOrganizationUsers = DB::table('organization_user')
            ->where('organization_id', $facility->organization_id)
            ->whereIn('role', ['owner', 'admin'])
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $staff = $staff->map(function ($member) use (
            $activeOwnerCount, $request, $protectedOrganizationUsers,
            $actorCanManagePermissions, $orgLeadership, $actorIsFacilityOwner, $actorIsFacilityAdmin, $actorIsOrganizationOwner, $profiles
        ): array {
            $self = (int) $member->id === (int) $request->user()->id;
            $protectedOrg = in_array((int) $member->id, $protectedOrganizationUsers, true);
            $protectedFacility = in_array($member->role, ['facility_manager', 'facility_owner', 'facility_admin'], true);

            $canEditPermissions = ! $self && ! $protectedOrg && ! $protectedFacility && $actorCanManagePermissions;
            $canEditRoles = ! $self && ($orgLeadership || $actorIsFacilityOwner || $actorIsFacilityAdmin)
                && (! $protectedOrg || $actorIsOrganizationOwner)
                && (! $protectedFacility || $orgLeadership || $actorIsFacilityOwner)
                && ($member->role !== 'facility_owner' || $actorIsOrganizationOwner || $actorIsFacilityOwner);
            $canRemove = ! $self && ($orgLeadership || $actorIsFacilityOwner || $actorIsFacilityAdmin)
                && ! in_array($member->organization_role, ['owner', 'admin'], true)
                && ($member->role !== 'facility_owner' || $actorIsOrganizationOwner || $actorIsFacilityOwner)
                && ($member->role !== 'facility_admin' || $orgLeadership || $actorIsFacilityOwner);

            // A status is shown only when no management action is available.
            // These reasons are derived from the same server-side eligibility checks as the links.
            $managementStatus = null;
            if (! $canEditPermissions && ! $canEditRoles && ! $canRemove) {
                $managementStatus = match (true) {
                    $self => 'Cannot modify your own permissions',
                    $member->organization_role === 'owner' => 'Protected — Organization Owner',
                    $member->role === 'facility_owner' => 'Protected — Facility Owner',
                    $member->organization_role === 'admin' => 'Protected — Organization Admin',
                    $member->role === 'facility_admin' => 'Protected — Facility Admin',
                    $member->role === 'facility_manager' => 'Protected — Facility Manager',
                    default => 'Insufficient management authority',
                };
            }

            $profile = $profiles->get($member->id);
            $primaryPhone = $profile?->contactMethods?->first(fn ($contact) => $contact->kind === 'phone' && $contact->is_primary)?->value;
            $primaryEmail = $profile?->contactMethods?->first(fn ($contact) => $contact->kind === 'email' && $contact->is_primary)?->value;

            return [
                'id' => $member->id,
                'phone' => $primaryPhone ?: ($profile?->phone ?: $profile?->mobile_phone),
                'email' => $primaryEmail ?: $member->email,
                'name' => $member->name,
                'role' => $member->role,
                'organizationRole' => $member->organization_role,
                'isLastFacilityOwner' => $member->role === 'facility_owner' && $activeOwnerCount === 1,
                'canEditPermissions' => $canEditPermissions,
                'canEditRoles' => $canEditRoles,
                'canRemove' => $canRemove,
                'managementStatus' => $managementStatus,
            ];
        });

        return Inertia::render('facilities/StaffRoster', [
            'facility' => $facility->only('id', 'name'),
            'staff' => $staff,
            'canManageStaffPermissions' => $permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_STAFF_PERMISSIONS),
            'currentUserId' => $request->user()->id,
            'actorOrganizationRole' => $actorOrgRole,
            'actorFacilityRole' => DB::table('organization_staff_facilities')
                ->where('organization_id', $facility->organization_id)
                ->where('facility_id', $facility->id)
                ->where('user_id', $request->user()->id)
                ->value('role'),
            'eligibleMembers' => $organizations->current($request)?->users()->wherePivot('status', 'active')->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])->filter(fn ($member) => ! $staff->contains('id', $member->id))->values()->map(fn ($member) => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email]),
            'canCreateOrganizationStaff' => in_array($organizations->current($request)?->users()->whereKey($request->user()->id)->first()?->pivot?->role, ['owner', 'admin'], true),
            'canAssignOwner' => in_array($organizations->current($request)?->users()->whereKey($request->user()->id)->first()?->pivot?->role, ['owner'], true) || DB::table('organization_staff_facilities')->where('organization_id', $facility->organization_id)->where('facility_id', $facility->id)->where('user_id', $request->user()->id)->where('role', 'facility_owner')->exists(),
            'canAssignRoles' => in_array($organizationRole = $organizations->current($request)?->users()
                ->whereKey($request->user()->id)->first()?->pivot?->role, ['owner', 'admin'], true)
                || DB::table('organization_staff_facilities')->where('organization_id', $facility->organization_id)
                    ->where('facility_id', $facility->id)->where('user_id', $request->user()->id)
                    ->whereIn('role', ['facility_owner', 'facility_admin'])->exists(),
        ]);
    }
}
