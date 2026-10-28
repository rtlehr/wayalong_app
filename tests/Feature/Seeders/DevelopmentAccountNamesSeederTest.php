<?php

use App\Models\User;
use Database\Seeders\BlueRidgeFacilityAccountsSeeder;
use Database\Seeders\DevelopmentAccountNamesSeeder;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('labels development logins by organization or facility and primary role', function (): void {
    $this->seed(DevelopmentSeeder::class);
    $this->seed(BlueRidgeFacilityAccountsSeeder::class);
    $this->seed(DevelopmentAccountNamesSeeder::class);

    $expected = [
        'dev.platform.admin@wayalong.test' => 'WayAlong PlatformAdmin',
        'dev.valley.owner@wayalong.test' => 'ValleyYouthServices Owner',
        'dev.ridge.owner@wayalong.test' => 'BlueRidge Owner',
        'dev.ridge.clinician@wayalong.test' => 'BlueRidgeTreatment Clinician',
        'dev.ridge.treatment.owner@wayalong.test' => 'BlueRidgeTreatment Owner',
        'dev.ridge.treatment.admin@wayalong.test' => 'BlueRidgeTreatment Admin',
        'dev.ridge.nova.owner@wayalong.test' => 'NorthernVirginiaCounseling Owner',
        'dev.ridge.nova.admin@wayalong.test' => 'NorthernVirginiaCounseling Admin',
    ];

    foreach ($expected as $email => $name) {
        expect(User::query()->where('email', $email)->firstOrFail()->name)->toBe($name);
    }
});

it('renames existing demo users without modifying their password or roles', function (): void {
    $this->seed(DevelopmentSeeder::class);
    $this->seed(BlueRidgeFacilityAccountsSeeder::class);

    $email = 'dev.ridge.treatment.owner@wayalong.test';
    $user = User::query()->where('email', $email)->firstOrFail();
    $user->forceFill(['name' => 'Old Demo Name'])->save();

    $before = DB::table('users')->where('id', $user->id)->first();
    $facilityRoles = DB::table('organization_staff_facilities')->where('user_id', $user->id)->pluck('role', 'facility_id')->all();
    $orgRoles = DB::table('organization_user')->where('user_id', $user->id)->pluck('role', 'organization_id')->all();

    $this->seed(DevelopmentAccountNamesSeeder::class);
    $this->seed(DevelopmentAccountNamesSeeder::class);

    $after = DB::table('users')->where('id', $user->id)->first();
    expect($after->name)->toBe('BlueRidgeTreatment Owner')
        ->and($after->email)->toBe($before->email)
        ->and($after->password)->toBe($before->password)
        ->and($after->is_active)->toBe($before->is_active)
        ->and(DB::table('organization_staff_facilities')->where('user_id', $user->id)->pluck('role', 'facility_id')->all())->toBe($facilityRoles)
        ->and(DB::table('organization_user')->where('user_id', $user->id)->pluck('role', 'organization_id')->all())->toBe($orgRoles);
});
