<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Notifications\InvitationResponseNotification;
use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationInvitationController extends Controller
{
    public function store(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): RedirectResponse
    {
        $organization = $context->current($request);
        abort_unless($organization && $permissions->allows($request->user(), $organization, OrganizationPermissions::MANAGE_MEMBERS), 403);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'role' => ['required', Rule::in(['owner', 'admin', 'clinician', 'staff'])], 'facility_id' => ['nullable', 'integer', Rule::exists('facilities', 'id')->where('organization_id', $organization->id)->where('status', 'active')]]);
        $email = Str::lower(trim($data['email']));
        if ($data['role'] === 'owner') {
            abort_unless($organization->users()->whereKey($request->user()->id)->wherePivot('role', 'owner')->exists(), 403);
        }
        $existing = User::where('email', $email)->first();
        if ($existing && $organization->users()->whereKey($existing->id)->exists() && empty($data['facility_id'])) {
            throw ValidationException::withMessages(['email' => 'This person already has an organization membership.']);
        }
        if ($existing && ! empty($data['facility_id']) && DB::table('organization_staff_facilities')
            ->where('organization_id', $organization->id)->where('facility_id', $data['facility_id'])
            ->where('user_id', $existing->id)->exists()) {
            throw ValidationException::withMessages(['facility_id' => 'This person is already assigned to this facility.']);
        }
        $token = Str::random(64);
        DB::transaction(function () use ($organization, $email, $data, $request, $token) {
            // Replace any pending invitation for this organization/email.
            OrganizationInvitation::where('organization_id', $organization->id)->where('email', $email)->whereNull('accepted_at')->delete();
            OrganizationInvitation::create([
                'organization_id' => $organization->id, 'email' => $email, 'role' => $data['role'], 'facility_id' => $data['facility_id'] ?? null,
                'token_hash' => hash('sha256', $token), 'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(7),
            ]);
        });
        $notification = new OrganizationInvitationNotification($organization->name, route('invitations.show', ['token' => $token], false));
        if ($existing !== null) {
            $existing->notify($notification);
        } else {
            Notification::route('mail', $email)->notify($notification);
        }
        return back()->with('success', 'Invitation sent.');
    }

    private function invitation(string $token): OrganizationInvitation
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token), 404);
        $invitation = OrganizationInvitation::where('token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($invitation->isValid(), 410, 'Invitation has expired or is no longer available.');
        return $invitation;
    }

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->invitation($token);
        return Inertia::render('invitations/Accept', [
            'token' => $token, 'email' => $invitation->email,
            'organization' => $invitation->organization->name,
            'organizationSlug' => $invitation->organization->slug,
            'facility' => $invitation->facility_id !== null
                ? $this->invitedFacility($invitation)?->name
                : null,
            'signInUrl' => route('invitations.sign-in', ['token' => $token]),
            'signedInAsInvited' => $request->user()?->email === $invitation->email,
            'existingAccount' => User::where('email', $invitation->email)->exists(),
            'signedIn' => $request->user() !== null,
        ]);
    }

    /** Preserve the invitation destination through Fortify's existing login flow. */
    public function signIn(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitation($token);
        abort_unless(User::where('email', $invitation->email)->exists(), 404);
        $request->session()->put('url.intended', route('invitations.show', ['token' => $token]));
        // The invited person is NOT a member of this organization yet.
        // Authenticate the central identity without organization scoping first.
        $request->session()->forget(\App\Http\Controllers\OrganizationLoginController::LOGIN_ORGANIZATION_KEY);

        return redirect()->route('login');
    }

    /**
     * Invitation recipients can belong to a different active organization.
     * Resolve only the facility identified by this invitation and explicitly
     * enforce its owning organization and active status.
     */
    private function invitedFacility(OrganizationInvitation $invitation): ?Facility
    {
        if ($invitation->facility_id === null) {
            return null;
        }

        return Facility::withoutGlobalScope('organization')
            ->whereKey($invitation->facility_id)
            ->where('organization_id', $invitation->organization_id)
            ->where('status', 'active')
            ->first();
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        // Account creation is offered only to invited email addresses without an account.
        $invitation = $this->invitation($token);
        $existing = User::where('email', $invitation->email)->first();
        if ($existing) {
            abort_unless($existing->is_active, 403, 'This account is inactive.');
            abort_unless($request->user() && $request->user()->id === $existing->id, 403, 'Sign in with the invited account first.');
        } else {
            abort_if($request->user(), 403, 'Sign out to register with the invited email address.');
            $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:12', 'confirmed'],
            ]);
        }

        $user = DB::transaction(function () use ($invitation, $existing, $request) {
            $locked = OrganizationInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isValid(), 410);
            // Recheck membership and account status while holding the invitation lock.
            // A revoked or inactive central identity must never be linked.
            if ($existing !== null) {
                abort_unless($existing->fresh()?->is_active, 403);
            }
            // Owner privileges must still be authorized at acceptance time.
            // An owner invitation cannot outlive the inviter's owner authority.
            if ($locked->role === 'owner') {
                abort_unless($locked->invited_by !== null && $locked->organization->users()
                    ->whereKey($locked->invited_by)
                    ->wherePivot('role', 'owner')
                    ->wherePivot('status', 'active')
                    ->exists(), 403, 'The owner invitation is no longer authorized.');
            }
            $user = $existing ?? User::create([
                'name' => $request->string('name')->toString(),
                'email' => $locked->email,
                'password' => $request->input('password'),
            ]);
            if (! $locked->organization->users()->whereKey($user->id)->exists()) {
                $locked->organization->users()->attach($user->id, ['role' => $locked->role, 'status' => 'active']);
            } elseif ($locked->facility_id === null || ! $locked->organization->users()->whereKey($user->id)->wherePivot('status', 'active')->exists()) {
                throw ValidationException::withMessages(['email' => 'An active membership is required for an additional facility invitation.']);
            }
            if ($locked->facility_id !== null) {
                if (DB::table('organization_staff_facilities')->where('organization_id', $locked->organization_id)
                    ->where('facility_id', $locked->facility_id)->where('user_id', $user->id)->exists()) {
                    throw ValidationException::withMessages(['facility_id' => 'This person already belongs to this facility.']);
                }
                abort_unless($this->invitedFacility($locked) !== null, 403, 'Facility is no longer available.');
                DB::table('organization_staff_facilities')->updateOrInsert(
                    ['organization_id' => $locked->organization_id, 'facility_id' => $locked->facility_id, 'user_id' => $user->id],
                    ['role' => $locked->role, 'updated_at' => now(), 'created_at' => now()]
                );
            }
            $locked->update(['accepted_at' => now()]);
            return $user;
        });

        if (! $request->user()) {
            auth()->login($user);
            $request->session()->regenerate();
            // Standard Fortify email verification continues for newly registered accounts.
            event(new \Illuminate\Auth\Events\Registered($user));
        }
        $invitation->inviter?->notify(new InvitationResponseNotification($invitation->email, $invitation->organization->name, 'accepted'));
        $request->session()->put(OrganizationContext::SESSION_KEY, $invitation->organization_id);
        return redirect()->route('dashboard')->with('success', 'Invitation accepted.');
    }

    public function decline(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitation($token);
        abort_unless($request->user() && $request->user()->is_active && strcasecmp($request->user()->email, $invitation->email) === 0, 403);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($invitation, $data): void {
            $locked = OrganizationInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isValid(), 410);
            $locked->update(['declined_at' => now(), 'decline_reason' => $data['reason'] ?? null]);
        });
        $invitation->inviter?->notify(new InvitationResponseNotification($invitation->email, $invitation->organization->name, 'declined', $data['reason'] ?? null));
        return redirect()->route('messages.index')->with('success', 'Invitation declined.');
    }

}
