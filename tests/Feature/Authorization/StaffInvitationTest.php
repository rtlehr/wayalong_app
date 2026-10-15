<?php

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function invitationOwner(string $role = 'owner'): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => $role, 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    return [$organization, $user];
}

function invitationToken(Organization $organization, string $email, string $role = 'staff'): array
{
    $token = str_repeat('A', 64);
    $invitation = OrganizationInvitation::create([
        'organization_id' => $organization->id, 'email' => $email, 'role' => $role,
        'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7),
    ]);
    return [$invitation, $token];
}

it('allows an owner to send a one-week invitation', function () {
    Notification::fake();
    [$organization] = invitationOwner();
    $this->post(route('organization.admin.invitations.store'), ['email' => 'NEW@example.com', 'role' => 'clinician'])->assertRedirect();
    $invite = OrganizationInvitation::firstOrFail();
    expect($invite->email)->toBe('new@example.com')->and($invite->role)->toBe('clinician')
        ->and($invite->token_hash)->toHaveLength(64)->and($invite->expires_at->isFuture())->toBeTrue();
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
});

it('rejects invitations from non administrators', function () {
    invitationOwner('staff');
    $this->post(route('organization.admin.invitations.store'), ['email' => 'new@example.com', 'role' => 'staff'])->assertForbidden();
});

it('does not allow an administrator to invite an owner', function () {
    invitationOwner('admin');
    $this->post(route('organization.admin.invitations.store'), ['email' => 'new@example.com', 'role' => 'owner'])->assertForbidden();
});

it('does not allow duplicate active members to be invited', function () {
    [$organization] = invitationOwner();
    $member = User::factory()->create();
    $organization->users()->attach($member);
    $this->post(route('organization.admin.invitations.store'), ['email' => $member->email, 'role' => 'staff'])->assertSessionHasErrors('email');
});

it('creates an invited account and activates membership', function () {
    $organization = Organization::factory()->create();
    [, $token] = invitationToken($organization, 'invited@example.com', 'clinician');
    $this->post(route('invitations.accept', $token), ['name' => 'Invited Person', 'password' => 'a-secure-password-123', 'password_confirmation' => 'a-secure-password-123'])->assertRedirect(route('dashboard'));
    $user = User::where('email', 'invited@example.com')->firstOrFail();
    expect($organization->users()->whereKey($user->id)->first()->pivot->role)->toBe('clinician');
    expect(OrganizationInvitation::first()->accepted_at)->not->toBeNull();
});

it('requires invited existing users to be signed in as themselves', function () {
    $organization = Organization::factory()->create();
    $invited = User::factory()->create();
    [, $token] = invitationToken($organization, $invited->email);
    $this->post(route('invitations.accept', $token))->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('invitations.accept', $token))->assertForbidden();
    $this->actingAs($invited)->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));
    expect($organization->users()->whereKey($invited->id)->exists())->toBeTrue();
});

it('rejects expired, consumed and inactive-organization invitations', function () {
    $organization = Organization::factory()->create();
    [$invite, $token] = invitationToken($organization, 'invited@example.com');
    $invite->update(['expires_at' => now()->subMinute()]);
    $this->get(route('invitations.show', $token))->assertStatus(410);
    $invite->update(['expires_at' => now()->addDay(), 'accepted_at' => now()]);
    $this->get(route('invitations.show', $token))->assertStatus(410);
    $invite->update(['accepted_at' => null]);
    $organization->update(['status' => 'inactive']);
    $this->get(route('invitations.show', $token))->assertStatus(410);
});

it('rejects invalid invitation tokens and prevents replay', function () {
    $this->get(route('invitations.show', 'invalid'))->assertNotFound();
    $organization = Organization::factory()->create();
    [, $token] = invitationToken($organization, 'invited@example.com');
    $payload = ['name' => 'Invited Person', 'password' => 'a-secure-password-123', 'password_confirmation' => 'a-secure-password-123'];
    $this->post(route('invitations.accept', $token), $payload)->assertRedirect();
    $this->post(route('invitations.accept', $token), $payload)->assertStatus(410);
});
