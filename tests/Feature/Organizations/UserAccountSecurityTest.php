<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function accountAdmin(Organization $organization): User
{
    $owner = User::factory()->create();
    $owner->organizations()->attach($organization, ['role' => 'owner', 'status' => 'active']);
    test()->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    return $owner;
}

it('stores multiple contact methods and keeps their details within the shared user account', function () {
    $organization = Organization::factory()->create();
    accountAdmin($organization);
    $person = User::factory()->create();
    $person->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $this->patch(route('organization.admin.users.update', $person->id), [
        'name' => $person->name, 'email' => $person->email, 'facility_ids' => [],
        'contacts' => [
            ['kind' => 'phone', 'label' => 'Home', 'is_primary' => true, 'value' => '555-1000'],
            ['kind' => 'phone', 'label' => 'Work', 'is_primary' => false, 'value' => '555-2000'],
            ['kind' => 'email', 'label' => 'Personal', 'is_primary' => true, 'value' => 'alternate@example.com'],
            ['kind' => 'address', 'label' => 'Mailing', 'is_primary' => true, 'line_1' => '100 Main St', 'city' => 'Winchester', 'state' => 'VA'],
        ],
    ])->assertSessionHasNoErrors();
    expect(\App\Models\OrganizationUserProfile::where('organization_id', $organization->id)->where('user_id', $person->id)->firstOrFail()->contactMethods()->count())->toBe(4);
    $profile = \App\Models\OrganizationUserProfile::where('organization_id', $organization->id)
        ->where('user_id', $person->id)->firstOrFail();
    $address = $profile->contactMethods()->where('kind', 'address')->firstOrFail();
    expect($address->line_1)->toBe('100 Main St');
    expect($address->city)->toBe('Winchester');
    expect($address->state)->toBe('VA');

    $stored = \Illuminate\Support\Facades\DB::table('contact_methods')->where('id', $address->id)->first();
    expect($stored->line_1)->not->toBe('100 Main St');
    expect($stored->city)->not->toBe('Winchester');
    expect($stored->state)->not->toBe('VA');
    expect($person->fresh()->email)->toBe($person->email);
});

it('does not allow two primary contact methods of the same type', function () {
    $organization = Organization::factory()->create();
    accountAdmin($organization);
    $person = User::factory()->create();
    $person->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $this->patch(route('organization.admin.users.update', $person->id), [
        'name' => $person->name, 'email' => $person->email, 'facility_ids' => [],
        'contacts' => [
            ['kind' => 'phone', 'label' => 'Home', 'is_primary' => true, 'value' => '555-1'],
            ['kind' => 'phone', 'label' => 'Work', 'is_primary' => true, 'value' => '555-2'],
        ],
    ])->assertSessionHasErrors('contacts');
    expect($person->contactMethods()->count())->toBe(0);
});

it('sends a secure password link rather than an administrator-selected password', function () {
    Notification::fake();
    $organization = Organization::factory()->create();
    accountAdmin($organization);
    $person = User::factory()->create();
    $person->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $this->post(route('organization.admin.users.password-reset', $person->id))->assertRedirect();
    Notification::assertSentTo($person, ResetPassword::class);
});

it('blocks disabled accounts from authenticated access and refuses cross-organization global deactivation', function () {
    $organization = Organization::factory()->create();
    $owner = accountAdmin($organization);
    $person = User::factory()->create();
    $person->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $this->patch(route('organization.admin.users.active', $person->id), ['is_active' => false])->assertRedirect();
    expect($person->fresh()->is_active)->toBeTrue();
    expect($organization->users()->whereKey($person->id)->firstOrFail()->pivot->status)->toBe('inactive');
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $other = Organization::factory()->create();
    $shared = User::factory()->create();
    $shared->organizations()->attach($organization, ['role' => 'staff', 'status' => 'active']);
    $shared->organizations()->attach($other, ['role' => 'staff', 'status' => 'active']);
    $this->patch(route('organization.admin.users.active', $shared->id), ['is_active' => false])->assertRedirect();
    expect($shared->fresh()->is_active)->toBeTrue();
    expect($other->users()->whereKey($shared->id)->firstOrFail()->pivot->status)->toBe('active');
});
