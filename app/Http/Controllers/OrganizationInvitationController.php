<?php

namespace App\Http\Controllers;

use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'role' => ['required', Rule::in(['owner', 'admin', 'clinician', 'staff'])]]);
        $email = Str::lower(trim($data['email']));
        if ($data['role'] === 'owner') {
            abort_unless($organization->users()->whereKey($request->user()->id)->wherePivot('role', 'owner')->exists(), 403);
        }
        $existing = User::where('email', $email)->first();
        if ($existing && $organization->users()->whereKey($existing->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This person already has an organization membership.']);
        }
        $token = Str::random(64);
        DB::transaction(function () use ($organization, $email, $data, $request, $token) {
            // Replace any pending invitation for this organization/email.
            OrganizationInvitation::where('organization_id', $organization->id)->where('email', $email)->whereNull('accepted_at')->delete();
            OrganizationInvitation::create([
                'organization_id' => $organization->id, 'email' => $email, 'role' => $data['role'],
                'token_hash' => hash('sha256', $token), 'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(7),
            ]);
        });
        Notification::route('mail', $email)->notify(new OrganizationInvitationNotification($organization->name, route('invitations.show', ['token' => $token])));
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
            'existingAccount' => User::where('email', $invitation->email)->exists(),
            'signedIn' => $request->user() !== null,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        // Account creation is offered only to invited email addresses without an account.
        $invitation = $this->invitation($token);
        $existing = User::where('email', $invitation->email)->first();
        if ($existing) {
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
            } else {
                throw ValidationException::withMessages(['email' => 'Membership already exists.']);
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
        $request->session()->put(OrganizationContext::SESSION_KEY, $invitation->organization_id);
        return redirect()->route('dashboard')->with('success', 'Invitation accepted.');
    }
}
