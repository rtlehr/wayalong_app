<?php

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

function phaseTwoOwner(Organization $organization): User
{
    $owner = User::factory()->create();
    $owner->organizations()->attach($organization, ['role' => 'owner', 'status' => 'active']);
    return $owner;
}

it('revokes a formerly authorized administrator immediately after demotion', function () {
    $organization = Organization::factory()->create();
    $owner = phaseTwoOwner($organization);
    $admin = User::factory()->create();
    $admin->organizations()->attach($organization, ['role' => 'admin', 'status' => 'active']);

    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $this->get(route('organization.admin.staff.index'))->assertOk();

    $organization->users()->updateExistingPivot($admin->id, ['role' => 'staff']);
    $this->get(route('organization.admin.staff.index'))->assertForbidden();
    expect(app(OrganizationPermissions::class)->allows($admin, $organization, OrganizationPermissions::MANAGE_MEMBERS))->toBeFalse();
});

it('does not transfer administrative rights between organizations for the same user', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user = phaseTwoOwner($first);
    $user->organizations()->attach($second, ['role' => 'clinician', 'status' => 'active']);

    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $first->id]);
    $this->get(route('organization.admin.staff.index'))->assertOk();
    $this->post(route('organizations.switch'), ['organization_id' => $second->id])->assertRedirect();
    $this->get(route('organization.admin.staff.index'))->assertForbidden();
});

it('rejects an owner invitation if its inviter has been demoted before acceptance', function () {
    Notification::fake();
    $organization = Organization::factory()->create();
    $owner = phaseTwoOwner($organization);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $this->post(route('organization.admin.invitations.store'), ['email' => 'new-owner@example.test', 'role' => 'owner'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $invitation = OrganizationInvitation::firstOrFail();

    // Simulate an owner demotion by another owner after issuing the invitation.
    $organization->users()->updateExistingPivot($owner->id, ['role' => 'staff']);
    $token = null;
    Notification::assertSentOnDemand(\App\Notifications\OrganizationInvitationNotification::class, function ($notification) use (&$token) {
        if (preg_match('~/invitations/([A-Za-z0-9]{64})~', $notification->invitationUrl, $match)) {
            $token = $match[1];
        }
        return true;
    });
    expect($token)->not->toBeNull();
    auth()->logout();
    $this->post(route('invitations.accept', $token), [
        'name' => 'New Owner', 'password' => 'secure-test-pass-123', 'password_confirmation' => 'secure-test-pass-123',
    ])->assertForbidden();
    expect($invitation->fresh()->accepted_at)->toBeNull()
        ->and($organization->users()->where('users.email', 'new-owner@example.test')->exists())->toBeFalse();
});

it('rejects expired invitations even when the invited account exists', function () {
    $organization = Organization::factory()->create();
    $existing = User::factory()->create();
    $token = str_repeat('B', 64);
    OrganizationInvitation::create([
        'organization_id' => $organization->id,
        'email' => $existing->email,
        'role' => 'staff',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->subSecond(),
    ]);
    $this->actingAs($existing)->post(route('invitations.accept', $token))->assertStatus(410);
    expect($organization->users()->whereKey($existing->id)->exists())->toBeFalse();
});

it('refuses to accept invitations under a suspended organization', function () {
    $organization = Organization::factory()->create(['status' => 'inactive']);
    $token = str_repeat('C', 64);
    OrganizationInvitation::create([
        'organization_id' => $organization->id,
        'email' => 'suspended@example.test',
        'role' => 'staff',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDay(),
    ]);
    $this->post(route('invitations.accept', $token), [
        'name' => 'Suspended', 'password' => 'secure-test-pass-123', 'password_confirmation' => 'secure-test-pass-123',
    ])->assertStatus(410);
    expect(User::where('email', 'suspended@example.test')->exists())->toBeFalse();
});
