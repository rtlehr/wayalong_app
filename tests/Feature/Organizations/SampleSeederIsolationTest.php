<?php

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\QATestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('creates independent organization accounts and repeatable facility assignments in development', function () {
    $this->seed(DevelopmentSeeder::class);
    $valley = Organization::query()->where('slug', 'valley-youth-services')->firstOrFail();
    $ridge = Organization::query()->where('slug', 'blue-ridge-behavioral-health')->firstOrFail();
    $valleyClinician = User::query()->where('email', 'dev.valley.clinician@wayalong.test')->firstOrFail();
    $ridgeClinician = User::query()->where('email', 'dev.ridge.clinician@wayalong.test')->firstOrFail();

    expect($valleyClinician->id)->not->toBe($ridgeClinician->id)
        ->and($valleyClinician->name)->toBe($ridgeClinician->name)
        ->and($valleyClinician->organizations()->pluck('organizations.id')->all())->toBe([$valley->id])
        ->and($ridgeClinician->organizations()->pluck('organizations.id')->all())->toBe([$ridge->id]);

    $accountsBefore = User::query()->count();
    $assignmentsBefore = DB::table('organization_staff_facilities')->count();
    $contactsBefore = DB::table('contact_methods')->count();
    $this->seed(DevelopmentSeeder::class);

    expect(User::query()->count())->toBe($accountsBefore)
        ->and(DB::table('organization_staff_facilities')->count())->toBe($assignmentsBefore)
        ->and(DB::table('contact_methods')->count())->toBe($contactsBefore)
        ->and(User::query()->where('is_platform_admin', true)->count())->toBe(1);
});

it('seeds organization-isolated QA accounts and inactive user scenarios', function () {
    $this->seed(QATestSeeder::class);
    $alpha = User::query()->where('email', 'qa.alpha.clinician@wayalong.test')->firstOrFail();
    $beta = User::query()->where('email', 'qa.beta.clinician@wayalong.test')->firstOrFail();
    $disabled = User::query()->where('email', 'qa.alpha.disabled@wayalong.test')->firstOrFail();

    expect($alpha->id)->not->toBe($beta->id)
        ->and($alpha->name)->toBe($beta->name)
        ->and($alpha->organizations()->count())->toBe(1)
        ->and($beta->organizations()->count())->toBe(1)
        ->and($disabled->is_active)->toBeFalse();
});
