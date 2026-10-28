<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Support\FacilityOwnershipGuard;
use App\Support\FacilityPermissions;
use App\Support\FacilityRoleAssignments;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class FacilityRoleAssignmentController extends Controller
{
    public function facilityEdit(Request $request, OrganizationContext $context, int $facility, int $member): Response
    {
        $organization = $context->current($request);
        abort_unless($organization !== null && $organization->status === 'active', 403);
        $actor = $organization->users()->whereKey($request->user()->id)->wherePivot('status', 'active')->firstOrFail();
        $target = $organization->users()->whereKey($member)->wherePivot('status', 'active')->firstOrFail();
        $location = $organization->facilities()->whereKey($facility)->where('status', 'active')->firstOrFail();
        $isOwner = $actor->pivot->role === 'owner';
        $isAdmin = $actor->pivot->role === 'admin';
        $isFacilityOwner = DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
            ->where('facility_id', $location->id)->where('user_id', $request->user()->id)
            ->where('role', 'facility_owner')->exists();
        $isFacilityAdmin = DB::table('organization_staff_facilities')
            ->where('organization_id', $organization->id)->where('facility_id', $location->id)
            ->where('user_id', $request->user()->id)->where('role', 'facility_admin')->exists();
        abort_unless($isOwner || $isAdmin || $isFacilityOwner || $isFacilityAdmin, 403);
        abort_if($request->user()->id === $target->id, 403);
        $assignment = DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
            ->where('facility_id', $location->id)->where('user_id', $target->id)->first();
        abort_unless($assignment !== null, 404);
        abort_if(in_array($target->pivot->role, ['owner', 'admin'], true) && ! $isOwner, 403);
        abort_if(in_array($assignment->role, ['facility_owner', 'facility_admin'], true)
            && ! ($isOwner || $isFacilityOwner), 403);
        return Inertia::render('facilities/StaffRoles', [
            'facility' => $location->only('id', 'name'),
            'member' => $target->only('id', 'name'),
            'primaryRole' => $assignment->role ?? 'staff',
            'additionalRoles' => app(FacilityRoleAssignments::class)->additional($assignment->id),
            'canAssignOwner' => $isOwner || $isFacilityOwner,
        ]);
    }

    public function facilityUpdate(Request $request, OrganizationContext $context, int $facility, int $member): RedirectResponse
    {
        abort_if((int) $request->input('facility_id') !== $facility, 403);
        return $this->update($request, $context, $member);
    }

    public function update(Request $request, OrganizationContext $context, int $member): RedirectResponse
    {
        $organization = $context->current($request);
        abort_unless($organization !== null && $organization->status === 'active', 403);
        $actor = $organization->users()->whereKey($request->user()->id)->wherePivot('status', 'active')->firstOrFail();
        $target = $organization->users()->whereKey($member)->wherePivot('status', 'active')->firstOrFail();
        $data = $request->validate([
            'facility_id' => ['required', 'integer', Rule::exists('facilities', 'id')->where('organization_id', $organization->id)],
            'primary_role' => ['required', Rule::in(['staff', 'clinician', 'floor_staff', 'client_admin', 'facility_manager', 'facility_admin', 'facility_owner'])],
            'additional_roles' => ['present', 'array', 'max:5'],
            'additional_roles.*' => ['string', 'distinct', Rule::in(FacilityRoleAssignments::ADDITIONAL_ROLES)],
        ]);
        $facility = $organization->facilities()->whereKey($data['facility_id'])->where('status', 'active')->firstOrFail();
        $actorIsOrganizationOwner = $actor->pivot->role === 'owner';
        $actorIsOrganizationAdmin = $actor->pivot->role === 'admin';
        $actorIsFacilityOwner = DB::table('organization_staff_facilities')
            ->where('organization_id', $organization->id)->where('facility_id', $facility->id)
            ->where('user_id', $request->user()->id)->where('role', 'facility_owner')->exists();
        $actorIsFacilityAdmin = DB::table('organization_staff_facilities')
            ->where('organization_id', $organization->id)->where('facility_id', $facility->id)
            ->where('user_id', $request->user()->id)->where('role', 'facility_admin')->exists();
        abort_unless($actorIsOrganizationOwner || $actorIsOrganizationAdmin || $actorIsFacilityOwner || $actorIsFacilityAdmin, 403);
        abort_if(in_array($target->pivot->role, ['owner', 'admin'], true) && ! $actorIsOrganizationOwner, 403);
        $targetPrimaryRole = DB::table('organization_staff_facilities')
            ->where('organization_id', $organization->id)->where('facility_id', $facility->id)
            ->where('user_id', $target->id)->value('role');
        if (in_array($data['primary_role'], ['facility_owner', 'facility_admin'], true) || in_array($targetPrimaryRole, ['facility_owner', 'facility_admin'], true)) {
            abort_unless($actorIsOrganizationOwner || $actorIsFacilityOwner, 403);
        }
        // Assigning Facility Owner is reserved for an active Owner at organization or facility level.
        // Checking this on the server also rejects forged form submissions.
        if ($data['primary_role'] === 'facility_owner') {
            abort_unless($actorIsOrganizationOwner || $actorIsFacilityOwner, 403, 'Only Owners can appoint Facility Owners.');
        }
        abort_if($member === $request->user()->id, 403, 'Self-assigned leadership changes are prohibited.');
        DB::transaction(function () use ($organization, $target, $facility, $data, $request): void {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $assignment = DB::table('organization_staff_facilities')
                ->where('organization_id', $organization->id)->where('facility_id', $facility->id)
                ->where('user_id', $target->id)->lockForUpdate()->first();
            abort_unless($assignment !== null, 404);
            if ($assignment->role !== $data['primary_role']) {
                app(FacilityOwnershipGuard::class)->assertCanRemove(
                    $organization->id, $facility->id, $assignment->id, $assignment->role
                );
            }
            $oldAdditional = app(FacilityRoleAssignments::class)->additional($assignment->id);
            DB::table('organization_staff_facilities')->where('id', $assignment->id)
                ->update(['role' => $data['primary_role'], 'updated_at' => now()]);
            DB::table('facility_assignment_roles')->where('assignment_id', $assignment->id)->delete();
            $additional = array_values(array_unique(array_diff($data['additional_roles'], [$data['primary_role']])));
            foreach ($additional as $role) {
                DB::table('facility_assignment_roles')->insert([
                    'assignment_id' => $assignment->id, 'role' => $role,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($assignment->role !== $data['primary_role'] || $oldAdditional !== $additional) {
                DB::table('facility_role_change_events')->insert([
                    'organization_id' => $organization->id, 'facility_id' => $facility->id,
                    'user_id' => $target->id, 'actor_id' => $request->user()->id,
                    'old_primary_role' => $assignment->role, 'new_primary_role' => $data['primary_role'],
                    'old_additional_roles' => json_encode($oldAdditional),
                    'new_additional_roles' => json_encode($additional), 'created_at' => now(),
                ]);
            }
        });
        return back()->with('success', 'Facility roles updated.');
    }
}
