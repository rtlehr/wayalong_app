<?php

use App\Models\Organization;
use App\Models\OrganizationUserProfile;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('keeps one identity while organizations maintain separate profiles, contacts and membership status', function () {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $adminA = User::factory()->create();
    $adminB = User::factory()->create();
    $member = User::factory()->create(['name' => 'Central Name', 'phone' => 'CENTRAL']);
    $adminA->organizations()->attach($a, ['role' => 'owner', 'status' => 'active']);
    $adminB->organizations()->attach($b, ['role' => 'owner', 'status' => 'active']);
    $member->organizations()->attach($a, ['role' => 'staff', 'status' => 'active']);
    $member->organizations()->attach($b, ['role' => 'staff', 'status' => 'active']);

    $payload = fn (string $display, string $phone) => [
        'name' => 'Central Name', 'email' => $member->email,
        'display_name' => $display, 'phone' => $phone, 'facility_ids' => [],
        'contacts' => [['kind' => 'phone', 'label' => 'Office', 'is_primary' => true, 'value' => $phone]],
    ];

    $this->actingAs($adminA)->withSession([OrganizationContext::SESSION_KEY => $a->id])
        ->patch(route('organization.admin.users.update', $member->id), $payload('Org A Name', '111-1111'))
        ->assertSessionHasNoErrors();
    $this->actingAs($adminB)->withSession([OrganizationContext::SESSION_KEY => $b->id])
        ->patch(route('organization.admin.users.update', $member->id), $payload('Org B Name', '222-2222'))
        ->assertSessionHasNoErrors();

    $profileA = OrganizationUserProfile::where('organization_id', $a->id)->where('user_id', $member->id)->firstOrFail();
    $profileB = OrganizationUserProfile::where('organization_id', $b->id)->where('user_id', $member->id)->firstOrFail();
    expect($profileA->display_name)->toBe('Org A Name')
        ->and($profileB->display_name)->toBe('Org B Name')
        ->and($profileA->contactMethods()->firstOrFail()->value)->toBe('111-1111')
        ->and($profileB->contactMethods()->firstOrFail()->value)->toBe('222-2222')
        ->and($member->fresh()->name)->toBe('Central Name')
        ->and($member->fresh()->phone)->toBe('CENTRAL');

    $this->actingAs($adminA)->withSession([OrganizationContext::SESSION_KEY => $a->id])
        ->patch(route('organization.admin.users.active', $member->id), ['is_active' => false])->assertRedirect();
    expect(DB::table('organization_user')->where('organization_id', $a->id)->where('user_id', $member->id)->value('status'))->toBe('inactive')
        ->and(DB::table('organization_user')->where('organization_id', $b->id)->where('user_id', $member->id)->value('status'))->toBe('active')
        ->and($member->fresh()->is_active)->toBeTrue();
});

it('prevents deactivating the final active organization owner', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $owner->organizations()->attach($organization, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id])
        ->patch(route('organization.admin.users.active', $owner->id), ['is_active' => false])->assertStatus(422);
    expect(DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $owner->id)->value('status'))->toBe('active');
});


it('keeps an active owner when a second owner is deactivated through the status endpoint', function () {
    $organization = Organization::factory()->create();
    $firstOwner = User::factory()->create();
    $secondOwner = User::factory()->create();
    $firstOwner->organizations()->attach($organization, ['role' => 'owner', 'status' => 'active']);
    $secondOwner->organizations()->attach($organization, ['role' => 'owner', 'status' => 'active']);

    $this->actingAs($firstOwner)->withSession([OrganizationContext::SESSION_KEY => $organization->id])
        ->patch(route('organization.admin.users.active', $secondOwner->id), ['is_active' => false])->assertRedirect();
    expect(DB::table('organization_user')->where('organization_id', $organization->id)
        ->where('user_id', $secondOwner->id)->value('status'))->toBe('inactive');

    $this->patch(route('organization.admin.users.active', $firstOwner->id), ['is_active' => false])
        ->assertStatus(422);
    expect(DB::table('organization_user')->where('organization_id', $organization->id)
        ->where('user_id', $firstOwner->id)->value('status'))->toBe('active');
});
