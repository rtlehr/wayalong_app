<?php

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\QATestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('loads fictional development organizations, facilities and isolated organization memberships', function () {
    $this->seed(DevelopmentSeeder::class);

    expect(Organization::query()->whereIn('slug', [
        'valley-youth-services', 'blue-ridge-behavioral-health', 'community-recovery-network',
    ])->count())->toBe(3);
    expect(DB::table('facilities')->count())->toBe(5);

    $valleyOwner = User::where('email', 'dev.valley.owner@wayalong.test')->firstOrFail();
    $ridgeClinician = User::where('email', 'dev.ridge.clinician@wayalong.test')->firstOrFail();
    $valleyClinician = User::where('email', 'dev.valley.clinician@wayalong.test')->firstOrFail();

    expect($valleyOwner->organizations)->toHaveCount(1)
        ->and($valleyOwner->organizations->first()->slug)->toBe('valley-youth-services')
        ->and($valleyOwner->organizations->first()->pivot->role)->toBe('owner')
        ->and($ridgeClinician->organizations)->toHaveCount(1)
        ->and($ridgeClinician->organizations->first()->slug)->toBe('blue-ridge-behavioral-health')
        ->and($ridgeClinician->id)->not->toBe($valleyClinician->id)
        ->and($ridgeClinician->name)->toBe($valleyClinician->name)
        ->and(User::where('email', 'dev.valley.inactive@wayalong.test')->firstOrFail()->is_active)->toBeFalse();
});

it('can re-run the development seeder without duplicate rows or lost passwords', function () {
    $this->seed(DevelopmentSeeder::class);
    $owner = User::where('email', 'dev.valley.owner@wayalong.test')->firstOrFail();
    $owner->update(['password' => 'changed-password']);
    $originalHash = $owner->password;
    $usersBefore = User::count();
    $contactsBefore = DB::table('contact_methods')->count();
    $facilitiesBefore = DB::table('organization_staff_facilities')->count();
    $this->seed(DevelopmentSeeder::class);

    expect(Organization::count())->toBe(3)
        ->and(DB::table('facilities')->count())->toBe(5)
        ->and(User::count())->toBe($usersBefore)
        ->and(User::where('email', 'dev.valley.owner@wayalong.test')->count())->toBe(1)
        ->and($owner->fresh()->password)->toBe($originalHash)
        ->and(DB::table('organization_user')->count())->toBe(8)
        ->and(DB::table('contact_methods')->count())->toBe($contactsBefore)
        ->and(DB::table('organization_staff_facilities')->count())->toBe($facilitiesBefore);
});

it('creates deterministic QA personas including inactive accounts and a separate platform administrator', function () {
    $this->seed(QATestSeeder::class);
    $this->seed(QATestSeeder::class);

    expect(Organization::count())->toBe(3)
        ->and(DB::table('facilities')->count())->toBe(4)
        ->and(User::where('email', 'like', 'qa.%@wayalong.test')->count())->toBe(9)
        ->and(DB::table('organization_user')->count())->toBe(8);

    $platformAdmin = User::where('email', 'qa.platform.admin@wayalong.test')->firstOrFail();
    $alphaOwner = User::where('email', 'qa.alpha.owner@wayalong.test')->firstOrFail();
    $betaOwner = User::where('email', 'qa.beta.owner@wayalong.test')->firstOrFail();
    $inactive = User::where('email', 'qa.alpha.disabled@wayalong.test')->firstOrFail();

    expect($platformAdmin->is_platform_admin)->toBeTrue()
        ->and($platformAdmin->organizations)->toHaveCount(0)
        ->and($alphaOwner->organizations)->toHaveCount(1)
        ->and($betaOwner->organizations)->toHaveCount(1)
        ->and($alphaOwner->id)->not->toBe($betaOwner->id)
        ->and($inactive->is_active)->toBeFalse()
        ->and($inactive->organizations->first()->pivot->status)->toBe('inactive');
});

it('does not wipe other application data when seeding', function () {
    $other = User::factory()->create();
    $this->seed(QATestSeeder::class);
    expect(User::find($other->id))->not->toBeNull();
});

it('rejects running sample seeders outside local or testing environments', function () {
    app()->detectEnvironment(fn () => 'production');
    try {
        expect(fn () => (new DevelopmentSeeder())->run())->toThrow(LogicException::class)
            ->and(fn () => (new QATestSeeder())->run())->toThrow(LogicException::class);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});
