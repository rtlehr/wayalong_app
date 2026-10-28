<?php

use App\Models\Facility;
use App\Models\User;
use App\Support\FacilityPermissions;
use Database\Seeders\BlueRidgeFacilityAccountsSeeder;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('seeds separate facility owners and admins without giving admins treatment access', function (): void {
    $this->seed(DevelopmentSeeder::class);
    $this->seed(BlueRidgeFacilityAccountsSeeder::class);

    $organization = DB::table('organizations')->where('slug', 'blue-ridge-behavioral-health')->firstOrFail();
    $treatment = Facility::withoutGlobalScopes()->where('organization_id', $organization->id)->where('code', 'BR-TREAT')->firstOrFail();
    $counseling = Facility::withoutGlobalScopes()->where('organization_id', $organization->id)->where('code', 'NOVA')->firstOrFail();

    $treatmentOwner = User::query()->where('email', 'dev.ridge.treatment.owner@wayalong.test')->firstOrFail();
    $treatmentAdmin = User::query()->where('email', 'dev.ridge.treatment.admin@wayalong.test')->firstOrFail();
    $counselingOwner = User::query()->where('email', 'dev.ridge.nova.owner@wayalong.test')->firstOrFail();
    $counselingAdmin = User::query()->where('email', 'dev.ridge.nova.admin@wayalong.test')->firstOrFail();

    $permissions = app(FacilityPermissions::class);
    expect($permissions->allows($treatmentOwner, $treatment, FacilityPermissions::MANAGE_ALERTS))->toBeTrue()
        ->and($permissions->allows($treatmentOwner, $counseling, FacilityPermissions::MANAGE_ALERTS))->toBeFalse()
        ->and($permissions->allows($counselingOwner, $counseling, FacilityPermissions::MANAGE_ALERTS))->toBeTrue()
        ->and($permissions->allows($counselingOwner, $treatment, FacilityPermissions::MANAGE_ALERTS))->toBeFalse()
        ->and($permissions->allows($treatmentAdmin, $treatment, FacilityPermissions::MANAGE_CLIENTS))->toBeTrue()
        ->and($permissions->allows($treatmentAdmin, $treatment, FacilityPermissions::MANAGE_ALERTS))->toBeFalse()
        ->and($permissions->allows($counselingAdmin, $counseling, FacilityPermissions::MANAGE_CLIENTS))->toBeTrue()
        ->and($permissions->allows($counselingAdmin, $counseling, FacilityPermissions::MANAGE_ALERTS))->toBeFalse();
});

it('can be rerun without duplicating facility demo accounts or assignments', function (): void {
    $this->seed(DevelopmentSeeder::class);
    $this->seed(BlueRidgeFacilityAccountsSeeder::class);
    $this->seed(BlueRidgeFacilityAccountsSeeder::class);

    expect(DB::table('users')->whereIn('email', [
        'dev.ridge.treatment.owner@wayalong.test', 'dev.ridge.treatment.admin@wayalong.test',
        'dev.ridge.nova.owner@wayalong.test', 'dev.ridge.nova.admin@wayalong.test',
    ])->count())->toBe(4);

    $ids = DB::table('users')->whereIn('email', [
        'dev.ridge.treatment.owner@wayalong.test', 'dev.ridge.treatment.admin@wayalong.test',
        'dev.ridge.nova.owner@wayalong.test', 'dev.ridge.nova.admin@wayalong.test',
    ])->pluck('id');
    expect(DB::table('organization_staff_facilities')->whereIn('user_id', $ids)->count())->toBe(4);
});
