<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationUserProfile;
use App\Models\User;
use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationUserController extends Controller
{
    private function organization(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Organization
    {
        $organization = $context->current($request);
        abort_unless($organization !== null && $permissions->allows($request->user(), $organization, OrganizationPermissions::MANAGE_MEMBERS), 403);
        return $organization;
    }

    private function facilities(Organization $organization): array
    {
        return $organization->facilities()->orderBy('name')->get(['id', 'name', 'code'])->toArray();
    }

    private function userPayload(User $user, ?OrganizationUserProfile $profile = null): array
    {
        return array_merge($user->only(['id', 'name', 'email', 'is_active']), [
            'display_name' => $profile?->display_name ?? $user->name,
            'phone' => $profile?->phone, 'mobile_phone' => $profile?->mobile_phone,
            'address_line_1' => $profile?->address_line_1, 'address_line_2' => $profile?->address_line_2,
            'city' => $profile?->city, 'state' => $profile?->state, 'postal_code' => $profile?->postal_code,
        ]);
    }

    private function profile(Organization $organization, User $user): ?OrganizationUserProfile
    {
        return OrganizationUserProfile::query()->where('organization_id', $organization->id)
            ->where('user_id', $user->id)->first();
    }

    public function index(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Response
    {
        $organization = $this->organization($request, $context, $permissions);
        $query = trim((string) $request->query('search', ''));
        abort_if(mb_strlen($query) > 100, 422);
        $users = $organization->users()->when($query !== '', fn ($builder) => $builder->where(function ($nested) use ($query): void {
            $nested->where('users.name', 'like', '%'.$query.'%')->orWhere('users.email', 'like', '%'.$query.'%');
        }))->orderBy('users.name')->paginate(15)->withQueryString()
            ->through(fn (User $user): array => $this->userPayload($user, $this->profile($organization, $user)) + ['role' => $user->pivot->role, 'status' => $user->pivot->status]);

        return Inertia::render('organizations/Users', [
            'organization' => $organization->only('id', 'name'),
            'users' => $users,
            'search' => $query,
        ]);
    }

    public function create(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Response
    {
        $organization = $this->organization($request, $context, $permissions);
        return Inertia::render('organizations/UserForm', [
            'mode' => 'create', 'organization' => $organization->only('id', 'name'),
            'availableFacilities' => $this->facilities($organization), 'assignedFacilities' => [], 'contactMethods' => [], 'membershipStatus' => 'active',
        ]);
    }

    public function show(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $user): Response
    {
        return $this->form($request, $context, $permissions, $user, 'view');
    }

    public function edit(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $user): Response
    {
        return $this->form($request, $context, $permissions, $user, 'edit');
    }

    private function form(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $user, string $mode): Response
    {
        $organization = $this->organization($request, $context, $permissions);
        $member = $organization->users()->whereKey($user)->firstOrFail();
        $profile = $this->profile($organization, $member);
        return Inertia::render('organizations/UserForm', [
            'mode' => $mode,
            'organization' => $organization->only('id', 'name'),
            'user' => $this->userPayload($member, $profile),
            'availableFacilities' => $this->facilities($organization),
            'contactMethods' => $profile?->contactMethods()->orderBy('kind')->orderByDesc('is_primary')->get()->toArray() ?? [],
            'membershipStatus' => $member->pivot->status,
            'assignedFacilities' => DB::table('organization_staff_facilities')
                ->where('organization_id', $organization->id)->where('user_id', $member->id)->pluck('facility_id'),
        ]);
    }

    private function validData(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'membership_status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'email' => $user === null
                ? ['required', 'email', 'max:255', Rule::unique('users', 'email')]
                : ['required', 'email', 'max:255', Rule::in([$user->email])],
            'phone' => ['nullable', 'string', 'max:40'],
            'mobile_phone' => ['nullable', 'string', 'max:40'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'contacts' => ['sometimes', 'array', 'max:40'],
            'contacts.*.kind' => ['required', Rule::in(['email', 'phone', 'address'])],
            'contacts.*.label' => ['required', 'string', 'max:50'],
            'contacts.*.is_primary' => ['required', 'boolean'],
            'contacts.*.value' => ['nullable', 'string', 'max:255'],
            'contacts.*.line_1' => ['nullable', 'string', 'max:255'],
            'contacts.*.line_2' => ['nullable', 'string', 'max:255'],
            'contacts.*.city' => ['nullable', 'string', 'max:255'],
            'contacts.*.state' => ['nullable', 'string', 'max:100'],
            'contacts.*.postal_code' => ['nullable', 'string', 'max:30'],
            'contacts.*.country' => ['nullable', 'string', 'size:2'],
            'facility_ids' => ['present', 'array'],
            'facility_ids.*' => ['integer', 'distinct'],
        ]);
    }

    private function saveContacts(OrganizationUserProfile $profile, array $contacts): void
    {
        $primary = [];
        foreach ($contacts as $index => $contact) {
            $kind = $contact['kind'];
            if ($kind === 'email') {
                validator($contact, ['value' => ['required', 'email', 'max:255']])->validate();
                // The login email is canonical; additional emails are contact-only until verified.
            } elseif ($kind === 'phone') {
                validator($contact, ['value' => ['required', 'string', 'max:40']])->validate();
            } else {
                validator($contact, ['line_1' => ['required', 'string', 'max:255']])->validate();
            }
            if ($contact['is_primary']) {
                if (isset($primary[$kind])) {
                    throw ValidationException::withMessages(['contacts' => 'Only one primary contact is allowed for each type.']);
                }
                $primary[$kind] = $index;
            }
        }

        $profile->contactMethods()->delete();
        foreach ($contacts as $contact) {
            $profile->contactMethods()->create($contact);
        }
    }

    private function saveFacilities(Organization $organization, User $user, array $facilityIds): void
    {
        $allowed = $organization->facilities()->whereIn('id', $facilityIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($allowed) !== count($facilityIds)) {
            throw ValidationException::withMessages(['facility_ids' => 'All selected facilities must belong to this organization.']);
        }
        DB::table('facility_staff_profiles')->where('organization_id', $organization->id)->where('user_id', $user->id)
            ->whereNotIn('facility_id', $allowed)->delete();
        // Retain existing roles and secondary role assignments when editing user contact details.
        $existing = DB::table('organization_staff_facilities')
            ->where('organization_id', $organization->id)->where('user_id', $user->id)->get();
        foreach ($existing as $assignment) {
            if (! in_array((int) $assignment->facility_id, $allowed, true)) {
                app(\App\Support\FacilityOwnershipGuard::class)->assertCanRemove(
                    $organization->id, (int) $assignment->facility_id, (int) $assignment->id,
                    (string) $assignment->role
                );
                DB::table('organization_staff_facilities')->where('id', $assignment->id)->delete();
            }
        }
        $existingIds = $existing->pluck('facility_id')->map(fn ($id) => (int) $id)->all();
        foreach (array_diff($allowed, $existingIds) as $id) {
            DB::table('organization_staff_facilities')->insert([
                'organization_id' => $organization->id, 'user_id' => $user->id,
                'facility_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function store(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): RedirectResponse
    {
        $organization = $this->organization($request, $context, $permissions);
        $data = $this->validData($request);
        $facilities = $data['facility_ids'];
        $contacts = $data['contacts'] ?? [];
        $displayName = $data['display_name'] ?? $data['name'];
        unset($data['facility_ids'], $data['contacts'], $data['display_name'], $data['membership_status']);
        // Never silently duplicate a global identity. Existing accounts join via invitation.
        $user = DB::transaction(function () use ($data, $facilities, $contacts, $organization, $displayName): User {
            $member = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(64)]);
            $organization->users()->attach($member->id, ['role' => 'staff', 'status' => 'active']);
            $this->saveFacilities($organization, $member, $facilities);
            $profile = OrganizationUserProfile::create(['organization_id' => $organization->id, 'user_id' => $member->id,
                'display_name' => $displayName, 'phone' => $data['phone'] ?? null, 'mobile_phone' => $data['mobile_phone'] ?? null,
                'address_line_1' => $data['address_line_1'] ?? null, 'address_line_2' => $data['address_line_2'] ?? null,
                'city' => $data['city'] ?? null, 'state' => $data['state'] ?? null, 'postal_code' => $data['postal_code'] ?? null]);
            $this->saveContacts($profile, $contacts);
            return $member;
        });
        // Send an account setup link rather than disclosing or choosing a password for the user.
        try {
            $status = Password::sendResetLink(['email' => $user->email]);
        } catch (\Throwable $exception) {
            report($exception);
            $status = null;
        }
        return redirect()->route('organization.admin.users.edit', $user->id)
            ->with('success', $status === Password::RESET_LINK_SENT ? 'User created. Account setup email sent.' : 'User created. Account setup email could not be sent; retry through password reset.');
    }

    public function update(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $user): RedirectResponse
    {
        $organization = $this->organization($request, $context, $permissions);
        $member = $organization->users()->whereKey($user)->firstOrFail();
        $data = $this->validData($request, $member);
        $facilities = $data['facility_ids'];
        $contacts = $data['contacts'] ?? null;
        $status = $data['membership_status'] ?? $member->pivot->status;
        if ($status === 'inactive' && $member->pivot->role === 'owner' && $member->pivot->status === 'active') {
            $otherOwners = $organization->users()->wherePivot('role', 'owner')->wherePivot('status', 'active')
                ->where('users.id', '!=', $member->id)->count();
            abort_if($otherOwners === 0, 422, 'Cannot deactivate the final active organization owner.');
        }
        // Global login credentials, global status, and central contact methods are never
        // modified from organization administration.
        DB::transaction(function () use ($member, $data, $organization, $facilities, $contacts, $status): void {
            $this->saveFacilities($organization, $member, $facilities);
            $profile = OrganizationUserProfile::query()->firstOrCreate([
                'organization_id' => $organization->id, 'user_id' => $member->id,
            ]);
            $profile->update([
                'display_name' => $data['display_name'] ?? $data['name'],
                'phone' => $data['phone'] ?? null, 'mobile_phone' => $data['mobile_phone'] ?? null,
                'address_line_1' => $data['address_line_1'] ?? null,
                'address_line_2' => $data['address_line_2'] ?? null,
                'city' => $data['city'] ?? null, 'state' => $data['state'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
            ]);
            if ($contacts !== null) {
                $this->saveContacts($profile, $contacts);
            }
            if ($status === 'inactive' && $member->pivot->status === 'active') {
                app(\App\Support\FacilityOwnershipGuard::class)->assertCanDeactivateMember($organization->id, $member->id);
            }
            $organization->users()->updateExistingPivot($member->id, ['status' => $status]);
        });
        return redirect()->route('organization.admin.users.edit', $member->id)->with('success', 'User and facility assignments updated.');
    }

    public function sendPasswordReset(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $user): RedirectResponse
    {
        $organization = $this->organization($request, $context, $permissions);
        $member = $organization->users()->whereKey($user)->firstOrFail();
        abort_unless($member->is_active, 422, 'Reactivate the user before sending a reset link.');
        $limit = 'admin-password-link:'.$request->user()->id;
        abort_if(RateLimiter::tooManyAttempts($limit, 5), 429);
        RateLimiter::hit($limit, 3600);
        $status = Password::sendResetLink(['email' => $member->email]);
        Log::info('WayAlong administrator requested a password reset link', [
            'actor_id' => $request->user()->id, 'subject_id' => $member->id, 'organization_id' => $organization->id,
        ]);
        return back()->with($status === Password::RESET_LINK_SENT ? 'success' : 'error',
            $status === Password::RESET_LINK_SENT ? 'Secure password reset link sent to the account email.' : 'Unable to send reset link. Please try again later.');
    }

    public function setActive(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $user): RedirectResponse
    {
        $organization = $this->organization($request, $context, $permissions);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $status = $data['is_active'] ? 'active' : 'inactive';

        // Serialize status changes with other owner-removal paths. Without the
        // organization lock, two requests can each see the other active owner
        // and deactivate both memberships.
        DB::transaction(function () use ($organization, $user, $status): void {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $member = $organization->users()->whereKey($user)->firstOrFail();
            if ($status === 'inactive' && $member->pivot->status === 'active') {
                if ($member->pivot->role === 'owner') {
                    $remainingOwners = $organization->users()
                        ->wherePivot('role', 'owner')->wherePivot('status', 'active')
                        ->where('users.id', '!=', $member->id)->count();
                    abort_if($remainingOwners === 0, 422, 'Cannot deactivate the final active owner.');
                }
                app(\App\Support\FacilityOwnershipGuard::class)
                    ->assertCanDeactivateMember($organization->id, $member->id);
            }
            $organization->users()->updateExistingPivot($member->id, ['status' => $status]);
        });
        Log::info('WayAlong organization membership status changed', [
            'actor_id' => $request->user()->id, 'subject_id' => $user,
            'organization_id' => $organization->id, 'status' => $status,
        ]);
        return back()->with('success', $status === 'active' ? 'Organization membership reactivated.' : 'Organization membership deactivated. Other organizations are unaffected.');
    }

    public function destroy(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, int $user): RedirectResponse
    {
        $organization = $this->organization($request, $context, $permissions);
        DB::transaction(function () use ($organization, $request, $user): void {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $member = $organization->users()->whereKey($user)->firstOrFail();
            if ($member->pivot->role === 'owner') {
                abort_unless($organization->users()->wherePivot('role', 'owner')->wherePivot('status', 'active')->count() > 1, 422, 'Cannot remove the final active owner.');
                abort_unless($organization->users()->whereKey($request->user()->id)->wherePivot('role', 'owner')->exists(), 403);
            }
            app(\App\Support\FacilityOwnershipGuard::class)->assertCanDeactivateMember($organization->id, $member->id);
            DB::table('organization_staff_details')->where('organization_id', $organization->id)->where('user_id', $member->id)->delete();
            DB::table('facility_staff_profiles')->where('organization_id', $organization->id)->where('user_id', $member->id)->delete();
            DB::table('organization_staff_facilities')->where('organization_id', $organization->id)->where('user_id', $member->id)->delete();
            $profile = $this->profile($organization, $member);
            if ($profile !== null) {
                $profile->contactMethods()->delete();
                $profile->delete();
            }
            $organization->users()->detach($member->id);
        });
        return redirect()->route('organization.admin.users.index')->with('success', 'User removed from this organization. Shared account preserved.');
    }
}
