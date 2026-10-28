<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationAdministrationController extends Controller
{
    private function authorizedOrganization(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, string $permission): Organization
    {
        $organization = $context->current($request);
        abort_unless($organization !== null, 403);

        abort_unless($permissions->allows($request->user(), $organization, $permission), 403, 'Organization permission required.');

        return $organization;
    }

    public function index(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Response
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_ORGANIZATION);

        return Inertia::render('organizations/Admin', [
            'organization' => $organization->only('id', 'name', 'slug', 'status', 'timezone'),
            'facilities' => Facility::query()->with('contactMethods')->orderBy('name')->get([
                'id', 'name', 'code', 'status', 'address_line_1', 'address_line_2',
                'city', 'state', 'postal_code', 'phone', 'email', 'timezone',
            ]),
            'permissionGroups' => OrganizationPermissions::permissionGroups(),
            'roleTemplates' => OrganizationPermissions::roleTemplates(),
            'customRoles' => $organization->customRoles()->orderBy('name')->get(['id', 'name', 'permissions'])->map(fn ($role) => ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions, 'value' => 'custom:'.$role->id]),
            'members' => $organization->users()->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])
                ->map(fn ($user) => [
                    'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                    'role' => $user->pivot->role, 'status' => $user->pivot->status,
                ]),
        ]);
    }

    public function facilitiesPage(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Response
    {
        return $this->administrationPage($request, $context, $permissions, 'organizations/Facilities');
    }

    public function rolesPage(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Response
    {
        return $this->administrationPage($request, $context, $permissions, 'organizations/Roles');
    }

    public function membersPage(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Response
    {
        return $this->administrationPage($request, $context, $permissions, 'organizations/Members', OrganizationPermissions::MANAGE_MEMBERS);
    }

    private function administrationPage(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, string $page, string $permission = OrganizationPermissions::MANAGE_ORGANIZATION): Response
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, $permission);

        return Inertia::render($page, $this->administrationProps($organization));
    }

    private function administrationProps(Organization $organization): array
    {
        return [
            'organization' => $organization->only('id', 'name', 'slug', 'status', 'timezone'),
            'facilities' => Facility::query()->with('contactMethods')->where('organization_id', $organization->id)->orderBy('name')->get([
                'id', 'name', 'code', 'status', 'address_line_1', 'address_line_2',
                'city', 'state', 'postal_code', 'phone', 'email', 'timezone',
            ]),
            'permissionGroups' => OrganizationPermissions::permissionGroups(),
            'roleTemplates' => OrganizationPermissions::roleTemplates(),
            'customRoles' => $organization->customRoles()->orderBy('name')->get(['id', 'name', 'permissions'])->map(fn ($role) => ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions, 'value' => 'custom:'.$role->id]),
            'members' => $organization->users()->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])
                ->map(fn ($user) => [
                    'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                    'role' => $user->pivot->role, 'status' => $user->pivot->status,
                ]),
        ];
    }

    public function staffDirectory(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Response
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_MEMBERS);
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
            'role' => ['sometimes', 'nullable', 'string', 'max:50'],
            'facility_id' => ['sometimes', 'nullable', 'integer', Rule::exists('facilities', 'id')->where('organization_id', $organization->id)],
        ]);

        $search = trim($filters['search'] ?? '');
        $status = $filters['status'] ?? '';
        $role = $filters['role'] ?? '';
        if ($role !== '' && ! $permissions->validRole($organization, $role)) {
            throw ValidationException::withMessages(['role' => 'Invalid role for this organization.']);
        }

        $facilities = $organization->facilities()->orderBy('name')->get(['id', 'name', 'code']);
        $facilityId = (int) ($filters['facility_id'] ?? $facilities->first()?->id ?? 0);
        $members = $organization->users()
            ->whereIn('users.id', DB::table('organization_staff_facilities')
                ->where('organization_id', $organization->id)->where('facility_id', $facilityId)->select('user_id'))
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('users.name', 'like', '%'.$search.'%')
                    ->orWhere('users.email', 'like', '%'.$search.'%');
            }))
            ->when($status !== '', fn ($query) => $query->where('organization_user.status', $status))
            ->when($role !== '', fn ($query) => $query->where('organization_user.role', $role))
            ->orderBy('users.name')->orderBy('users.id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
                'status' => $member->pivot->status,
            ]);

        return Inertia::render('organizations/Staff', [
            'organization' => $organization->only('id', 'name'),
            'members' => $members,
            'filters' => ['search' => $search, 'status' => $status, 'role' => $role, 'facility_id' => $facilityId],
            'availableFacilities' => $facilities,
            'customRoles' => $organization->customRoles()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($item) => ['value' => 'custom:'.$item->id, 'name' => $item->name]),
            'isOwner' => $organization->users()->whereKey($request->user()->id)->wherePivot('role', 'owner')->exists(),
        ]);
    }

    private function staffFormProps(Request $request, Organization $organization): array
    {
        return [
            'organization' => $organization->only('id', 'name'),
            'customRoles' => $organization->customRoles()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($role) => ['value' => 'custom:'.$role->id, 'name' => $role->name]),
            'isOwner' => $organization->users()->whereKey($request->user()->id)->wherePivot('role', 'owner')->exists(),
            'eligibleUsers' => $organization->users()->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]),
            'availableFacilities' => $organization->facilities()->orderBy('name')->get(['id', 'name', 'code']),
        ];
    }

    public function createStaff(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Response
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_MEMBERS);
        return Inertia::render('organizations/StaffForm', $this->staffFormProps($request, $organization) + ['mode' => 'create']);
    }

    public function viewStaff(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $member): Response
    {
        return $this->staffMemberPage($request, $context, $permissions, $member, 'view');
    }

    public function editStaff(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $member): Response
    {
        return $this->staffMemberPage($request, $context, $permissions, $member, 'edit');
    }

    private function staffMemberPage(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $member, string $mode): Response
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_MEMBERS);
        $user = $organization->users()->whereKey($member)->firstOrFail();
        $assignedIds = DB::table('organization_staff_facilities')->where('organization_id', $organization->id)->where('user_id', $user->id)->pluck('facility_id')->map(fn ($id) => (int) $id);
        $facilityId = (int) ($request->query('facility_id') ?? $assignedIds->first() ?? 0);
        if ($facilityId !== 0 && ! $assignedIds->contains($facilityId)) {
            abort(404);
        }
        return Inertia::render('organizations/StaffForm', $this->staffFormProps($request, $organization) + [
            'selectedFacilityId' => $facilityId,
            'facilityStaffDetails' => $facilityId ? DB::table('facility_staff_profiles')->where('organization_id', $organization->id)->where('user_id', $user->id)->where('facility_id', $facilityId)->first() : null,
            'mode' => $mode,
            'member' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'role' => $user->pivot->role, 'status' => $user->pivot->status],
            'staffDetails' => DB::table('organization_staff_details')->where('organization_id', $organization->id)->where('user_id', $user->id)->first(),
            'assignedFacilities' => DB::table('organization_staff_facilities')->where('organization_id', $organization->id)->where('user_id', $user->id)->pluck('facility_id'),
            'permissionGroups' => OrganizationPermissions::permissionGroups(),
            'organizationOverrides' => app(\App\Support\MemberPermissionOverrides::class)->saved($organization->id, $user->id, null),
            'facilityOverrides' => $facilityId ? app(\App\Support\MemberPermissionOverrides::class)->saved($organization->id, $user->id, $facilityId) : [],
            'effectivePermissions' => $this->staffEffectivePermissions($organization, $user, $facilityId),
            'facilityRoleAssignment' => $facilityId ? DB::table('organization_staff_facilities')
                ->where('organization_id', $organization->id)->where('user_id', $user->id)
                ->where('facility_id', $facilityId)->first(['id', 'role']) : null,
            'facilityAdditionalRoles' => $facilityId ? (function () use ($organization, $user, $facilityId): array {
                $assignmentId = DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
                    ->where('user_id', $user->id)->where('facility_id', $facilityId)->value('id');
                return $assignmentId ? app(\App\Support\FacilityRoleAssignments::class)->additional((int) $assignmentId) : [];
            })() : [],
        ]);
    }

    private function staffEffectivePermissions(Organization $organization, User $user, int $facilityId): array
    {
        $result = [];
        $organizationPermissions = app(OrganizationPermissions::class);
        $facilityPermissions = app(\App\Support\FacilityPermissions::class);
        $facility = $facilityId ? $organization->facilities()->whereKey($facilityId)->first() : null;
        foreach (OrganizationPermissions::permissionGroups() as $group) {
            foreach ($group['permissions'] as $entry) {
                $key = $entry['key'];
                $result[$key] = str_starts_with($key, 'facility.')
                    ? ($facility !== null && $facilityPermissions->allows($user, $facility, $key))
                    : $organizationPermissions->allows($user, $organization, $key);
            }
        }
        return $result;
    }

    public function deleteStaff(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $member): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_MEMBERS);
        $facilityId = $request->integer('facility_id');
        if ($facilityId > 0) {
            abort_unless($organization->facilities()->whereKey($facilityId)->exists(), 404);
            abort_unless($organization->users()->whereKey($member)->exists(), 404);
            DB::transaction(function () use ($organization, $member, $facilityId): void {
                DB::table('facility_staff_profiles')->where('organization_id', $organization->id)
                    ->where('user_id', $member)->where('facility_id', $facilityId)->delete();
                $assignment = DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
                    ->where('user_id', $member)->where('facility_id', $facilityId)->first();
                if ($assignment !== null) {
                    app(\App\Support\FacilityOwnershipGuard::class)->assertCanRemove(
                        $organization->id, $facilityId, $assignment->id, $assignment->role
                    );
                    DB::table('organization_staff_facilities')->where('id', $assignment->id)->delete();
                }
            });
            return redirect()->route('organization.admin.staff.index', ['facility_id' => $facilityId])
                ->with('success', 'Staff assignment removed from this facility. User and organization membership preserved.');
        }
        DB::transaction(function () use ($organization, $request, $member): void {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $target = $organization->users()->whereKey($member)->firstOrFail();
            // Membership removal is not account deletion. Reuse the owner safety checks.
            $this->assertMembershipChangeAllowed($organization, $request->user(), $target, 'staff', 'inactive');
            foreach (DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
                ->where('user_id', $target->id)->get() as $assignment) {
                app(\App\Support\FacilityOwnershipGuard::class)->assertCanRemove(
                    $organization->id, $assignment->facility_id, $assignment->id, $assignment->role
                );
            }
            DB::table('organization_staff_facilities')->where('organization_id', $organization->id)->where('user_id', $target->id)->delete();
            DB::table('organization_staff_details')->where('organization_id', $organization->id)->where('user_id', $target->id)->delete();
            DB::table('facility_staff_profiles')->where('organization_id', $organization->id)->where('user_id', $target->id)->delete();
            $organization->users()->detach($target->id);
        });
        return redirect()->route('organization.admin.staff.index')->with('success', 'Staff membership removed. The user account remains available.');
    }

    public function update(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_ORGANIZATION);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone'],
        ]);
        $organization->update($data);

        return back()->with('success', 'Organization updated.');
    }

    private function assertMembershipChangeAllowed(Organization $organization, User $actor, User $member, string $role, string $status): void
    {
        $actorMembership = $organization->users()->whereKey($actor->id)->firstOrFail();
        $targetMembership = $organization->users()->whereKey($member->id)->firstOrFail();
        $actorIsOwner = $actorMembership->pivot->role === 'owner';
        $targetIsOwner = $targetMembership->pivot->role === 'owner';

        if (! $actorIsOwner && ($targetIsOwner || $role === 'owner')) {
            abort(403, 'Only owners can manage owner memberships.');
        }

        if ($targetIsOwner && ($role !== 'owner' || $status !== 'active')) {
            $activeOwnerCount = DB::table('organization_user')
                ->where('organization_id', $organization->id)
                ->where('role', 'owner')->where('status', 'active')->count();

            if ($targetMembership->pivot->status === 'active' && $activeOwnerCount <= 1) {
                throw ValidationException::withMessages(['role' => 'An organization must retain at least one active owner.']);
            }
        }
    }

    public function storeMember(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_MEMBERS);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::exists('users', 'email')],
            'role' => ['required', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) use ($organization, $permissions): void {
                if (! is_string($value) || ! $permissions->validRole($organization, $value)) {
                    $fail('Invalid role for this organization.');
                }
            }],
        ]);


        if ($data['role'] === 'owner') {
            $actorMembership = $organization->users()->whereKey($request->user()->id)->firstOrFail();
            abort_unless($actorMembership->pivot->role === 'owner', 403);
        }

        $member = User::query()->where('email', $data['email'])->firstOrFail();
        if ($organization->users()->whereKey($member->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This user already belongs to the organization.']);
        }

        $organization->users()->attach($member->id, ['role' => $data['role'], 'status' => 'active']);

        return redirect()->route('organization.admin.staff.index')->with('success', 'Staff member added.');
    }

    public function updateMember(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $member): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_MEMBERS);
        $data = $request->validate([
            'role' => ['required', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) use ($organization, $permissions): void {
                if (! is_string($value) || ! $permissions->validRole($organization, $value)) {
                    $fail('Invalid role for this organization.');
                }
            }],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        $target = $organization->users()->whereKey($member)->firstOrFail();

        DB::transaction(function () use ($organization, $request, $target, $data): void {
            // Serialize changes within one organization to protect its final active owner.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $this->assertMembershipChangeAllowed($organization, $request->user(), $target, $data['role'], $data['status']);
            if ($data['status'] === 'inactive' && $target->pivot->status === 'active') {
                app(\App\Support\FacilityOwnershipGuard::class)->assertCanDeactivateMember(
                    $organization->id, $target->id
                );
            }
            $organization->users()->updateExistingPivot($target->id, $data);
        });

        return redirect()->route('organization.admin.staff.index')->with('success', 'Staff membership updated.');
    }

    public function updateStaffDetails(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $member): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_MEMBERS);
        $organization->users()->whereKey($member)->firstOrFail();
        $data = $request->validate([
            'facility_id' => ['sometimes', 'integer', Rule::exists('facilities', 'id')->where('organization_id', $organization->id)],
            'job_title' => ['nullable', 'string', 'max:255'],
            'staff_type' => ['nullable', 'string', 'max:255'],
            'credentials' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'facility_ids' => ['required', 'array'],
            'facility_ids.*' => ['integer', 'distinct', Rule::exists('facilities', 'id')->where('organization_id', $organization->id)],
        ]);

        if (isset($data['facility_id'])) {
            $facilityId = (int) $data['facility_id'];
            abort_unless(DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
                ->where('user_id', $member)->where('facility_id', $facilityId)->exists(), 403);
            DB::table('facility_staff_profiles')->updateOrInsert(
                ['organization_id' => $organization->id, 'facility_id' => $facilityId, 'user_id' => $member],
                ['job_title' => $data['job_title'] ?? null, 'staff_type' => $data['staff_type'] ?? null,
                    'credentials' => $data['credentials'] ?? null, 'notes' => $data['notes'] ?? null, 'updated_at' => now()]
            );
            return redirect()->route('organization.admin.staff.edit', ['member' => $member, 'facility_id' => $facilityId])
                ->with('success', 'Facility staff details saved.');
        }
        DB::transaction(function () use ($organization, $member, $data): void {
            $now = now();
            DB::table('organization_staff_details')->updateOrInsert(
                ['organization_id' => $organization->id, 'user_id' => $member],
                ['job_title' => $data['job_title'] ?? null, 'staff_type' => $data['staff_type'] ?? null,
                    'credentials' => $data['credentials'] ?? null, 'notes' => $data['notes'] ?? null, 'updated_at' => $now]
            );
            $existing = DB::table('organization_staff_facilities')->where('organization_id', $organization->id)
                ->where('user_id', $member)->get();
            foreach ($existing as $assignment) {
                if (! in_array((int) $assignment->facility_id, array_map('intval', $data['facility_ids']), true)) {
                    app(\App\Support\FacilityOwnershipGuard::class)->assertCanRemove(
                        $organization->id, $assignment->facility_id, $assignment->id, $assignment->role
                    );
                    DB::table('organization_staff_facilities')->where('id', $assignment->id)->delete();
                }
            }
            $existingIds = $existing->pluck('facility_id')->map(fn ($id) => (int) $id)->all();
            foreach (array_diff(array_map('intval', $data['facility_ids']), $existingIds) as $facilityId) {
                DB::table('organization_staff_facilities')->insert([
                    'organization_id' => $organization->id, 'user_id' => $member, 'facility_id' => $facilityId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });

        return redirect()->route('organization.admin.staff.edit', $member)->with('success', 'Staff details saved.');
    }

    public function storeRole(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_MEMBERS);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('organization_roles', 'name')->where('organization_id', $organization->id)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(OrganizationPermissions::CUSTOM_ROLE_PERMISSIONS)],
        ]);
        $organization->customRoles()->create($data);
        return back()->with('success', 'Custom role created.');
    }

    public function updateRole(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $role): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_MEMBERS);
        $record = $organization->customRoles()->findOrFail($role);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('organization_roles', 'name')->where('organization_id', $organization->id)->ignore($record->id)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(OrganizationPermissions::CUSTOM_ROLE_PERMISSIONS)],
        ]);
        $record->update($data);
        return back()->with('success', 'Custom role updated.');
    }

    public function deleteRole(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $role): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_MEMBERS);
        $record = $organization->customRoles()->findOrFail($role);
        if ($organization->users()->wherePivot('role', 'custom:'.$record->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'Reassign members before deleting this role.']);
        }
        $record->delete();
        return back()->with('success', 'Custom role deleted.');
    }

    /** @return array<string, mixed> */
    private function facilityData(Request $request, int $organizationId, ?int $facilityId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('facilities', 'code')
                ->where('organization_id', $organizationId)->ignore($facilityId)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
            'contacts' => ['sometimes', 'array', 'max:30'],
            'contacts.*.kind' => ['required', Rule::in(['phone', 'email'])],
            'contacts.*.label' => ['required', 'string', 'max:50'],
            'contacts.*.value' => ['required', 'string', 'max:255'],
            'contacts.*.is_primary' => ['required', 'boolean'],
        ]);
    }

    /** Validate and normalize contact entries before writing any changes. */
    private function facilityContacts(array $data): ?array
    {
        if (! array_key_exists('contacts', $data)) {
            return null; // Older API callers retain single-contact compatibility.
        }

        $contacts = [];
        $primaries = [];
        foreach ($data['contacts'] as $contact) {
            $value = trim($contact['value']);
            if ($value === '') {
                throw ValidationException::withMessages(['contacts' => 'Remove empty contact entries before saving.']);
            }
            if ($contact['kind'] === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['contacts' => 'Enter a valid email address.']);
            }
            if (! empty($contact['is_primary'])) {
                if (isset($primaries[$contact['kind']])) {
                    throw ValidationException::withMessages(['contacts' => 'Only one primary contact is allowed per type.']);
                }
                $primaries[$contact['kind']] = true;
            }
            $contacts[] = ['kind' => $contact['kind'], 'label' => trim($contact['label']),
                'value' => $value, 'is_primary' => (bool) $contact['is_primary']];
        }
        foreach (['phone', 'email'] as $kind) {
            $indices = array_keys(array_filter($contacts, fn ($row) => $row['kind'] === $kind));
            if ($indices && ! isset($primaries[$kind])) {
                $contacts[$indices[0]]['is_primary'] = true;
            }
        }
        return $contacts;
    }

    private function persistFacilityContacts(Facility $facility, array $contacts): void
    {
        $facility->contactMethods()->whereIn('kind', ['phone', 'email'])->delete();
        foreach ($contacts as $contact) {
            $facility->contactMethods()->create($contact);
        }
        foreach (['phone', 'email'] as $kind) {
            $primary = collect($contacts)->first(fn ($row) => $row['kind'] === $kind && $row['is_primary']);
            $facility->{$kind} = $primary['value'] ?? null;
        }
        $facility->save();
    }

    public function storeFacility(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_FACILITIES);
        $data = $this->facilityData($request, $organization->id);
        $contacts = $this->facilityContacts($data);
        unset($data['contacts']);
        DB::transaction(function () use ($data, $contacts): void {
            $facility = Facility::create($data);
            if ($contacts !== null) {
                $this->persistFacilityContacts($facility, $contacts);
            }
        });

        return back()->with('success', 'Facility created.');
    }

    public function updateFacility(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $facility): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_FACILITIES);
        $record = Facility::findOrFail($facility);
        $data = $this->facilityData($request, $organization->id, $record->id);
        $contacts = $this->facilityContacts($data);
        unset($data['contacts']);
        DB::transaction(function () use ($record, $data, $contacts): void {
            $record->update($data);
            if ($contacts !== null) {
                $this->persistFacilityContacts($record, $contacts);
            }
        });

        return back()->with('success', 'Facility updated.');
    }

    public function deleteFacility(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $facility): RedirectResponse
    {
        $this->authorizedOrganization($request, $context, $permissions, OrganizationPermissions::MANAGE_FACILITIES);
        Facility::findOrFail($facility)->delete();

        return back()->with('success', 'Facility deleted.');
    }
}
