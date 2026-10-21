<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('passes without altering accounts when ownership is unambiguous', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $before = User::count();

    $this->artisan('wayalong:audit-user-ownership')->assertExitCode(0);

    expect(User::count())->toBe($before)
        ->and($user->fresh()->organizations()->count())->toBe(1);
});

it('blocks migration readiness for users shared across organizations', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user = User::factory()->create();
    $first->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $second->users()->attach($user, ['role' => 'staff', 'status' => 'active']);

    $this->artisan('wayalong:audit-user-ownership')->assertExitCode(1);

    expect($user->fresh()->organizations()->count())->toBe(2);
});

it('detects organizationless non-platform accounts but permits platform-only accounts', function () {
    User::factory()->create(['is_platform_admin' => true]);
    $this->artisan('wayalong:audit-user-ownership')->assertExitCode(0);

    User::factory()->create();
    $this->artisan('wayalong:audit-user-ownership')->assertExitCode(1);
});
