<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('previews changes without writing and restores only known existing development accounts when applied', function (): void {
    $organization = Organization::factory()->create(['slug' => 'blue-ridge-behavioral-health']);
    $owner = User::factory()->create(['email' => 'dev.ridge.owner@wayalong.test']);
    $clinician = User::factory()->create(['email' => 'dev.ridge.clinician@wayalong.test']);
    $outsider = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'staff', 'status' => 'active']);
    $organization->users()->attach($clinician, ['role' => 'staff', 'status' => 'active']);
    $organization->users()->attach($outsider, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $treatment = Facility::factory()->create(['organization_id' => $organization->id, 'code' => 'BR-TREAT']);
    $north = Facility::factory()->create(['organization_id' => $organization->id, 'code' => 'NOVA']);

    $this->artisan('wayalong:dev-reset-staff')->assertExitCode(0);
    expect(DB::table('organization_user')->where('user_id', $owner->id)->value('role'))->toBe('staff');
    $this->artisan('wayalong:dev-reset-staff', ['--apply' => true])->assertExitCode(0);
    expect(DB::table('organization_user')->where('user_id', $owner->id)->value('role'))->toBe('owner')
        ->and(DB::table('organization_user')->where('user_id', $clinician->id)->value('role'))->toBe('clinician')
        ->and(DB::table('organization_user')->where('user_id', $outsider->id)->value('role'))->toBe('staff')
        ->and(DB::table('organization_staff_facilities')->where('user_id', $owner->id)->count())->toBe(2)
        ->and(DB::table('organization_staff_facilities')->where('user_id', $clinician->id)->where('facility_id', $treatment->id)->value('role'))->toBe('clinician');
    $this->artisan('wayalong:dev-reset-staff', ['--apply' => true])->assertExitCode(0);
    expect(DB::table('organization_staff_facilities')->where('user_id', $owner->id)->count())->toBe(2)
        ->and(DB::table('organization_staff_facilities')->where('user_id', $owner->id)->where('facility_id', $north->id)->exists())->toBeTrue();
});

it('restores a demo account in its target organization while preserving other organization memberships and assignments', function (): void {
    $ridge = Organization::factory()->create(['slug' => 'blue-ridge-behavioral-health']);
    $other = Organization::factory()->create();
    $user = User::factory()->create(['email' => 'dev.ridge.owner@wayalong.test']);
    $ridge->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    $other->users()->attach($user, ['role' => 'admin', 'status' => 'inactive']);
    // Use a separately authorized actor when creating records in the other organization.
    // The demo user's other membership is deliberately inactive and must stay that way.
    $otherActor = User::factory()->create();
    $other->users()->attach($otherActor, ['role' => 'admin', 'status' => 'active']);

    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $ridge->id]);
    $treatment = Facility::factory()->create(['organization_id' => $ridge->id, 'code' => 'BR-TREAT']);
    Facility::factory()->create(['organization_id' => $ridge->id, 'code' => 'NOVA']);
    $unrelatedRidgeFacility = Facility::factory()->create(['organization_id' => $ridge->id, 'code' => 'EXTRA']);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $ridge->id, 'user_id' => $user->id,
        'facility_id' => $unrelatedRidgeFacility->id, 'role' => 'clinician',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($otherActor)->withSession([OrganizationContext::SESSION_KEY => $other->id]);
    $otherFacility = Facility::factory()->create(['organization_id' => $other->id, 'code' => 'OTHER']);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $other->id, 'user_id' => $user->id,
        'facility_id' => $otherFacility->id, 'role' => 'facility_admin',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $ridge->id]);
    $this->artisan('wayalong:dev-reset-staff')->assertExitCode(0);
    expect(DB::table('organization_user')->where('organization_id', $ridge->id)->where('user_id', $user->id)->value('role'))->toBe('staff');

    $this->artisan('wayalong:dev-reset-staff', ['--apply' => true])->assertExitCode(0);
    $this->artisan('wayalong:dev-reset-staff', ['--apply' => true])->assertExitCode(0);

    expect(DB::table('organization_user')->where('organization_id', $ridge->id)->where('user_id', $user->id)->value('role'))->toBe('owner')
        ->and(DB::table('organization_user')->where('organization_id', $other->id)->where('user_id', $user->id)->value('role'))->toBe('admin')
        ->and(DB::table('organization_user')->where('organization_id', $other->id)->where('user_id', $user->id)->value('status'))->toBe('inactive')
        ->and(DB::table('organization_staff_facilities')->where('organization_id', $other->id)->where('user_id', $user->id)->where('facility_id', $otherFacility->id)->value('role'))->toBe('facility_admin')
        ->and(DB::table('organization_staff_facilities')->where('organization_id', $ridge->id)->where('user_id', $user->id)->where('facility_id', $unrelatedRidgeFacility->id)->value('role'))->toBe('clinician')
        ->and(DB::table('organization_staff_facilities')->where('organization_id', $ridge->id)->where('user_id', $user->id)->where('facility_id', $treatment->id)->value('role'))->toBe('staff')
        ->and(DB::table('organization_staff_facilities')->where('user_id', $user->id)->count())->toBe(4);
});
