<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Support\FacilityContext;
use App\Support\FacilityOwnershipGuard;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Manage facility assignments; never deletes central identities or organization memberships. */
final class FacilityStaffManagementController extends Controller
{
    private function authorize(Request $request, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): \App\Models\Facility
    {
        $facility = $facilities->current($request, $organizations);
        abort_unless($facility !== null && $permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_STAFF_PERMISSIONS), 403);
        $organization = $organizations->current($request);
        abort_unless($organization !== null && (int) $organization->id === (int) $facility->organization_id, 403);
        $membership = $organization->users()->whereKey($request->user()->id)->wherePivot('status', 'active')->first();
        $orgAdmin = in_array($membership?->pivot?->role, ['owner', 'admin'], true);
        $facilityLeader = DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
            ->where('facility_id', $facility->id)->where('user_id', $request->user()->id)
            ->whereIn('role', ['facility_owner', 'facility_admin'])->exists();
        abort_unless($orgAdmin || $facilityLeader, 403);
        return $facility;
    }

    public function store(Request $request, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): RedirectResponse
    {
        $facility = $this->authorize($request, $organizations, $facilities, $permissions);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('organization_user', 'user_id')->where('organization_id', $facility->organization_id)->where('status', 'active')],
            'role' => ['required', Rule::in(['staff', 'clinician', 'floor_staff', 'client_admin', 'facility_manager', 'facility_admin', 'facility_owner'])],
        ]);
        abort_if((int) $data['user_id'] === (int) $request->user()->id, 403);
        if ($data['role'] === 'facility_owner') {
            $membershipRole = $organizations->current($request)->users()->whereKey($request->user()->id)->first()?->pivot?->role;
            $isFacilityOwner = DB::table('organization_staff_facilities')->where('organization_id', $facility->organization_id)
                ->where('facility_id', $facility->id)->where('user_id', $request->user()->id)->where('role', 'facility_owner')->exists();
            abort_unless($membershipRole === 'owner' || $isFacilityOwner, 403);
        }
        abort_unless(DB::table('users')->where('id', $data['user_id'])->where('is_active', true)->exists(), 422, 'Select an active staff account.');
        $alreadyAssigned = DB::table('organization_staff_facilities')->where('organization_id', $facility->organization_id)
            ->where('facility_id', $facility->id)->where('user_id', $data['user_id'])->exists();
        if ($alreadyAssigned) {
            throw ValidationException::withMessages(['user_id' => 'This person is already assigned to the facility. Use Roles to change their role.']);
        }
        DB::table('organization_staff_facilities')->insert([
            'organization_id' => $facility->organization_id, 'facility_id' => $facility->id,
            'user_id' => $data['user_id'], 'role' => $data['role'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        Log::info('WayAlong facility staff assignment created', [
            'actor_id' => $request->user()->id,
            'subject_id' => (int) $data['user_id'],
            'organization_id' => (int) $facility->organization_id,
            'facility_id' => (int) $facility->id,
            'role' => $data['role'],
        ]);
        return back()->with('success', 'Staff member assigned to this facility.');
    }

    public function destroy(Request $request, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions, int $member): RedirectResponse
    {
        $facility = $this->authorize($request, $organizations, $facilities, $permissions);
        abort_if($member === (int) $request->user()->id, 403);
        // Facility staff actions cannot strip an organization Owner/Admin membership.
        abort_if(DB::table('organization_user')->where('organization_id', $facility->organization_id)
            ->where('user_id', $member)->whereIn('role', ['owner', 'admin'])->exists(), 403);
        $removedRole = DB::transaction(function () use ($facility, $member): string {
            Organization::query()->whereKey($facility->organization_id)->lockForUpdate()->firstOrFail();
            $assignment = DB::table('organization_staff_facilities')->where('organization_id', $facility->organization_id)
                ->where('facility_id', $facility->id)->where('user_id', $member)->lockForUpdate()->first();
            abort_unless($assignment !== null, 404);
            if ($assignment->role === 'facility_owner') {
                $actorOrgOwner = DB::table('organization_user')
                    ->where('organization_id', $facility->organization_id)
                    ->where('user_id', auth()->id())->where('role', 'owner')->where('status', 'active')->exists();
                $actorFacilityOwner = DB::table('organization_staff_facilities')
                    ->where('organization_id', $facility->organization_id)
                    ->where('facility_id', $facility->id)->where('user_id', auth()->id())
                    ->where('role', 'facility_owner')->exists();
                abort_unless($actorOrgOwner || $actorFacilityOwner, 403);
            }
            app(FacilityOwnershipGuard::class)->assertCanRemove($facility->organization_id, $facility->id, $assignment->id, $assignment->role);
            DB::table('organization_staff_facilities')->where('id', $assignment->id)->delete();
            return $assignment->role;
        });
        Log::info('WayAlong facility staff assignment removed', [
            'actor_id' => $request->user()->id,
            'subject_id' => $member,
            'organization_id' => (int) $facility->organization_id,
            'facility_id' => (int) $facility->id,
            'role' => $removedRole,
        ]);
        return back()->with('success', 'Staff member removed from this facility. Their account and other assignments remain active.');
    }
}
