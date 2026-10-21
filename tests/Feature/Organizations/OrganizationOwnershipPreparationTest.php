<?php

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maintains a distinct optional owning organization without removing legacy memberships', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $first->id]);
    $user->organizations()->attach($first, ['role' => 'staff', 'status' => 'active']);

    expect($user->fresh()->owningOrganization->id)->toBe($first->id)
        ->and($user->fresh()->organizations()->count())->toBe(1);

    // This legacy relation can still exist until the next controlled split.
    $user->organizations()->attach($second, ['role' => 'staff', 'status' => 'active']);
    expect($user->fresh()->organizations()->count())->toBe(2);
});

it('assigns an owner to each development seed account without owning the platform administrator', function () {
    $this->seed(DevelopmentSeeder::class);

    $org = Organization::where('slug', 'valley-youth-services')->firstOrFail();
    $owner = User::where('email', 'dev.valley.owner@wayalong.test')->firstOrFail();
    $platform = User::where('email', 'dev.platform.admin@wayalong.test')->firstOrFail();

    expect((int) $owner->organization_id)->toBe($org->id)
        ->and($owner->organizations()->count())->toBe(1)
        ->and($platform->organization_id)->toBeNull();
});
