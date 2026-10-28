<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\InvitationResponseNotification;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function facilityInvitation(Organization $organization, string $email, ?int $facilityId = null, ?int $inviterId = null, string $role = 'staff'): array
{
    $token = Str::random(64);
    $invitation = OrganizationInvitation::create([
        'organization_id' => $organization->id,
        'email' => $email,
        'role' => $role,
        'facility_id' => $facilityId,
        'token_hash' => hash('sha256', $token),
        'invited_by' => $inviterId,
        'expires_at' => now()->addDays(7),
    ]);
    return [$invitation, $token];
}

it('accepts facility invitation without duplicating identity and notifies the inviter', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $organization->users()->attach($admin, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    $recipient = User::factory()->create();
    [$invitation, $token] = facilityInvitation($organization, $recipient->email, $facility->id, $admin->id, 'clinician');

    $this->actingAs($recipient)->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
    expect($organization->users()->whereKey($recipient->id)->firstOrFail()->pivot->role)->toBe('clinician');
    expect(DB::table('organization_staff_facilities')->where('organization_id', $organization->id)->where('facility_id', $facility->id)->where('user_id', $recipient->id)->value('role'))->toBe('clinician');
    expect($admin->notifications()->where('type', InvitationResponseNotification::class)->count())->toBe(1);
    $this->post(route('invitations.accept', $token))->assertStatus(410);
});

it('allows the same user to accept a second facility without changing their organization role', function () {
    $organization = Organization::factory()->create();
    $person = User::factory()->create();
    $organization->users()->attach($person, ['role' => 'admin', 'status' => 'active']);
    $this->actingAs($person)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $first = Facility::factory()->create(['organization_id' => $organization->id]);
    $second = Facility::factory()->create(['organization_id' => $organization->id]);
    [$invitation, $token] = facilityInvitation($organization, $person->email, $second->id, null, 'staff');
    DB::table('organization_staff_facilities')->insert(['organization_id' => $organization->id, 'facility_id' => $first->id, 'user_id' => $person->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($person)->post(route('invitations.accept', $token))->assertRedirect();
    expect($organization->users()->whereKey($person->id)->firstOrFail()->pivot->role)->toBe('admin');
    expect(DB::table('organization_staff_facilities')->where('organization_id', $organization->id)->where('user_id', $person->id)->count())->toBe(2);
    expect(DB::table('organization_staff_facilities')->where('facility_id', $second->id)->value('role'))->toBe('staff');
});

it('allows an invited person to decline and optionally explain why without membership creation', function () {
    $organization = Organization::factory()->create();
    $admin = User::factory()->create();
    $recipient = User::factory()->create();
    [$invitation, $token] = facilityInvitation($organization, $recipient->email, null, $admin->id);
    $this->actingAs($recipient)->post(route('invitations.decline', $token), ['reason' => 'Not available'])->assertRedirect(route('messages.index'));
    expect($invitation->fresh()->decline_reason)->toBe('Not available');
    expect($invitation->fresh()->declined_at)->not->toBeNull();
    expect($organization->users()->whereKey($recipient->id)->exists())->toBeFalse();
    expect($admin->notifications()->where('type', InvitationResponseNotification::class)->count())->toBe(1);
    $this->post(route('invitations.accept', $token))->assertStatus(410);
    $this->post(route('invitations.decline', $token))->assertStatus(410);
});

it('does not allow an unrelated person to decline another persons invitation', function () {
    $organization = Organization::factory()->create();
    $recipient = User::factory()->create();
    [$invitation, $token] = facilityInvitation($organization, $recipient->email);
    $this->actingAs(User::factory()->create())->post(route('invitations.decline', $token))->assertForbidden();
    expect($invitation->fresh()->declined_at)->toBeNull();
});

it('rejects a facility from another organization when sending an invitation', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $otherAdmin = User::factory()->create();
    $otherOrganization->users()->attach($otherAdmin, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($otherAdmin)->withSession([OrganizationContext::SESSION_KEY => $otherOrganization->id]);
    $outsiderFacility = Facility::factory()->create(['organization_id' => $otherOrganization->id]);
    $admin = User::factory()->create();
    $organization->users()->attach($admin, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($admin)->withSession([OrganizationContext::SESSION_KEY => $organization->id])
        ->post(route('organization.admin.invitations.store'), ['email' => 'new@example.test', 'role' => 'staff', 'facility_id' => $outsiderFacility->id])
        ->assertSessionHasErrors('facility_id');
    expect(OrganizationInvitation::count())->toBe(0);
});

it('only shows invitation action buttons to the invited account while the invitation is active', function () {
    $organization = Organization::factory()->create();
    $recipient = User::factory()->create();
    $outsider = User::factory()->create();
    [$invitation, $token] = facilityInvitation($organization, $recipient->email);
    foreach ([$recipient, $outsider] as $user) {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => \App\Notifications\OrganizationInvitationNotification::class,
            'data' => ['title' => 'Invite', 'url' => '/invitations/'.$token],
        ]);
    }
    $this->actingAs($recipient)->get('/messages')->assertInertia(fn (Assert $page) => $page
        ->component('personal/Messages')->where('messages.0.invitationToken', $token));
    $this->actingAs($outsider)->get('/messages')->assertInertia(fn (Assert $page) => $page
        ->component('personal/Messages')->where('messages.0.invitationToken', null));
    $invitation->update(['declined_at' => now()]);
    $this->actingAs($recipient)->get('/messages')->assertInertia(fn (Assert $page) => $page
        ->component('personal/Messages')->where('messages.0.invitationToken', null));
});

it('accepts an invitation to a facility in another organization while signed into the original organization', function () {
    $valley = Organization::factory()->create();
    $blueRidge = Organization::factory()->create();
    $valleyOwner = User::factory()->create();
    $recipient = User::factory()->create();
    $valley->users()->attach($valleyOwner, ['role' => 'owner', 'status' => 'active']);
    $blueRidge->users()->attach($recipient, ['role' => 'clinician', 'status' => 'active']);

    $this->actingAs($valleyOwner)->withSession([OrganizationContext::SESSION_KEY => $valley->id]);
    $valleyFacility = Facility::factory()->create(['organization_id' => $valley->id, 'status' => 'active']);
    [$invitation, $token] = facilityInvitation($valley, $recipient->email, $valleyFacility->id, $valleyOwner->id, 'clinician');

    $this->actingAs($recipient)->withSession([OrganizationContext::SESSION_KEY => $blueRidge->id]);
    $this->get(route('invitations.show', $token))->assertInertia(fn (Assert $page) => $page
        ->component('invitations/Accept')
        ->where('facility', $valleyFacility->name));
    $this->post(route('invitations.accept', $token))->assertRedirect(route('dashboard'));

    expect($invitation->fresh()->accepted_at)->not->toBeNull();
    expect(DB::table('organization_staff_facilities')
        ->where('organization_id', $valley->id)
        ->where('facility_id', $valleyFacility->id)
        ->where('user_id', $recipient->id)
        ->exists())->toBeTrue();
    expect($blueRidge->users()->whereKey($recipient->id)->wherePivot('status', 'active')->exists())->toBeTrue();
});


it('rejects a pending staff invitation after its inviter loses organization membership', function () {
    $organization = Organization::factory()->create();
    $inviter = User::factory()->create();
    $recipient = User::factory()->create();
    $organization->users()->attach($inviter, ['role' => 'admin', 'status' => 'active']);

    [$invitation, $token] = facilityInvitation($organization, $recipient->email, null, $inviter->id, 'staff');

    DB::table('organization_user')
        ->where('organization_id', $organization->id)
        ->where('user_id', $inviter->id)
        ->update(['status' => 'inactive']);

    $this->actingAs($recipient)
        ->post(route('invitations.accept', $token))
        ->assertForbidden();

    expect($invitation->fresh()->accepted_at)->toBeNull();
    expect($organization->users()->whereKey($recipient->id)->exists())->toBeFalse();
});


it('rejects a pending invitation after the inviter is demoted but remains active', function () {
    $organization = Organization::factory()->create();
    $inviter = User::factory()->create();
    $recipient = User::factory()->create();
    $organization->users()->attach($inviter, ['role' => 'admin', 'status' => 'active']);
    [$invitation, $token] = facilityInvitation($organization, $recipient->email, null, $inviter->id);

    DB::table('organization_user')->where('organization_id', $organization->id)
        ->where('user_id', $inviter->id)->update(['role' => 'staff']);

    $this->actingAs($recipient)->post(route('invitations.accept', $token))->assertForbidden();
    expect($invitation->fresh()->accepted_at)->toBeNull();
    expect($organization->users()->whereKey($recipient->id)->exists())->toBeFalse();
});

it('accepts a pending staff invitation when the inviter still has management authority', function () {
    $organization = Organization::factory()->create();
    $inviter = User::factory()->create();
    $recipient = User::factory()->create();
    $organization->users()->attach($inviter, ['role' => 'admin', 'status' => 'active']);
    [$invitation, $token] = facilityInvitation($organization, $recipient->email, null, $inviter->id);

    $this->actingAs($recipient)->post(route('invitations.accept', $token))
        ->assertRedirect(route('dashboard'));
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
    expect($organization->users()->whereKey($recipient->id)->wherePivot('status', 'active')->exists())->toBeTrue();
});
