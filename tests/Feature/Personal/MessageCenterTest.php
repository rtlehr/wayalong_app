<?php

use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders a personal dashboard with only active organization memberships', function () {
    $user = User::factory()->create();
    $active = Organization::factory()->create(['status' => 'active']);
    $inactive = Organization::factory()->create(['status' => 'inactive']);
    $active->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $inactive->users()->attach($user, ['role' => 'staff', 'status' => 'active']);

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('memberships', 1)
        ->where('memberships.0.name', $active->name));
});

it('only displays messages belonging to the authenticated user and marks their own as read', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    foreach ([[$user, 'Valley Youth Services'], [$other, 'Blue Ridge']] as [$recipient, $name]) {
        $recipient->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => OrganizationInvitationNotification::class,
            'data' => ['title' => 'Organization invitation', 'message' => $name, 'url' => '/invitations/example'],
        ]);
    }
    $own = $user->notifications()->firstOrFail();
    $foreign = $other->notifications()->firstOrFail();

    $this->actingAs($user)->get('/messages')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('personal/Messages')
        ->has('messages', 1)
        ->where('messages.0.id', $own->id));
    $this->patch('/messages/'.$foreign->id.'/read')->assertNotFound();
    expect($foreign->fresh()->read_at)->toBeNull();
    $this->patch('/messages/'.$own->id.'/read')->assertRedirect();
    expect($own->fresh()->read_at)->not->toBeNull();
});

it('requires authentication for messages and personal notification actions', function () {
    $this->get('/messages')->assertRedirect('/login');
    $this->patch('/messages/'.(string) \Illuminate\Support\Str::uuid().'/read')->assertRedirect('/login');
});
