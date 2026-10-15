<?php

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\QATestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('loads fictional development organizations facilities and mixed-role memberships', function () {
    $this->seed(DevelopmentSeeder::class);

    expect(Organization::query()->whereIn('slug', [
        'valley-youth-services', 'blue-ridge-behavioral-health', 'community-recovery-network',
    ])->count())->toBe(3);
    expect(DB::table('facilities')->count())->toBe(5);

    $owner = User::where('email', 'dev.owner@wayalong.test')->firstOrFail();
    expect($owner->organizations)->toHaveCount(3);
    expect($owner->organizations->firstWhere('slug', 'valley-youth-services')->pivot->role)->toBe('owner');
    expect($owner->organizations->firstWhere('slug', 'blue-ridge-behavioral-health')->pivot->role)->toBe('clinician');
    expect(User::where('email', 'dev.inactive@wayalong.test')->firstOrFail()
        ->organizations->first()->pivot->status)->toBe('inactive');
});

it('can re-run the development seeder without duplicate rows or lost passwords', function () {
    $this->seed(DevelopmentSeeder::class);
    $owner = User::where('email', 'dev.owner@wayalong.test')->firstOrFail();
    $owner->update(['password' => 'changed-password']);
    $originalHash = $owner->password;
    $this->seed(DevelopmentSeeder::class);

    expect(Organization::count())->toBe(3)
        ->and(DB::table('facilities')->count())->toBe(5)
        ->and(User::where('email', 'dev.owner@wayalong.test')->count())->toBe(1)
        ->and($owner->fresh()->password)->toBe($originalHash)
        ->and(DB::table('organization_user')->count())->toBe(8);
});

it('creates deterministic QA personas including outsiders and inactive accounts', function () {
    $this->seed(QATestSeeder::class);
    $this->seed(QATestSeeder::class);

    expect(Organization::count())->toBe(3)
        ->and(DB::table('facilities')->count())->toBe(4)
        ->and(User::where('email', 'like', 'qa.%@wayalong.test')->count())->toBe(6)
        ->and(DB::table('organization_user')->count())->toBe(8);

    expect(User::where('email', 'qa.outsider@wayalong.test')->firstOrFail()->organizations)->toHaveCount(0);
    expect(User::where('email', 'qa.disabled@wayalong.test')->firstOrFail()->organizations->first()->pivot->status)->toBe('inactive');
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
