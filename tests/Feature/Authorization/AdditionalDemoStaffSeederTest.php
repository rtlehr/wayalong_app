<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('seeds six stable demo identities and resets intended assigned and unassigned facility access', function (): void {
    $ridge = Organization::factory()->create(['slug' => 'blue-ridge-behavioral-health']);
    $valley = Organization::factory()->create(['slug' => 'valley-youth-services']);
    $actor = User::factory()->create();
    $ridge->users()->attach($actor, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($actor)->withSession([OrganizationContext::SESSION_KEY => $ridge->id]);
    $ridgeTreatment = Facility::factory()->create(['organization_id' => $ridge->id, 'code' => 'BR-TREAT']);
    $ridgeNorth = Facility::factory()->create(['organization_id' => $ridge->id, 'code' => 'NOVA']);
    $ridgeExtra = Facility::factory()->create(['organization_id' => $ridge->id, 'code' => 'EXTRA']);
    $valleyActor = User::factory()->create();
    $valley->users()->attach($valleyActor, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($valleyActor)->withSession([OrganizationContext::SESSION_KEY => $valley->id]);
    Facility::factory()->create(['organization_id' => $valley->id, 'code' => 'WIN-RES']);
    Facility::factory()->create(['organization_id' => $valley->id, 'code' => 'SHEN-OUT']);

    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\AdditionalDemoStaffSeeder'])->assertExitCode(0);
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\AdditionalDemoStaffSeeder'])->assertExitCode(0);
    expect(User::query()->where('email', 'like', 'dev.%.demo.%@wayalong.test')->count())->toBe(6);

    $assigned = User::query()->where('email', 'dev.ridge.demo.casey@wayalong.test')->firstOrFail();
    $unassigned = User::query()->where('email', 'dev.ridge.demo.jordan@wayalong.test')->firstOrFail();
    $passwordHash = $assigned->password;
    expect(DB::table('organization_staff_facilities')->where('user_id', $assigned->id)->where('facility_id', $ridgeTreatment->id)->value('role'))->toBe('clinician')
        ->and(DB::table('organization_staff_facilities')->where('user_id', $unassigned->id)->count())->toBe(0);

    DB::table('organization_staff_facilities')->where('user_id', $assigned->id)->where('facility_id', $ridgeTreatment->id)->update(['role' => 'staff']);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $ridge->id, 'user_id' => $unassigned->id, 'facility_id' => $ridgeExtra->id,
        'role' => 'staff', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->artisan('wayalong:dev-reset-staff')->assertExitCode(0);
    expect(DB::table('organization_staff_facilities')->where('user_id', $unassigned->id)->count())->toBe(1);

    $this->artisan('wayalong:dev-reset-staff', ['--apply' => true])->assertExitCode(0);
    $this->artisan('wayalong:dev-reset-staff', ['--apply' => true])->assertExitCode(0);
    expect(DB::table('organization_staff_facilities')->where('user_id', $assigned->id)->where('facility_id', $ridgeTreatment->id)->value('role'))->toBe('clinician')
        ->and(DB::table('organization_staff_facilities')->where('user_id', $unassigned->id)->count())->toBe(0)
        ->and($assigned->fresh()->password)->toBe($passwordHash)
        ->and(DB::table('organization_staff_facilities')->where('user_id', $assigned->id)->count())->toBe(1);
});
