<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function adminFixture(string $role = 'owner'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org, ['role' => $role]);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    return [$user, $org];
}

function facilityPayload(string $code = 'MAIN'): array
{
    return ['name' => 'Main Center', 'code' => $code, 'status' => 'active', 'city' => 'Winchester', 'state' => 'VA'];
}

it('requires login and organization membership for administration', function () {
    $this->get(route('organization.admin.index'))->assertRedirect();
    $this->actingAs(User::factory()->create())->get(route('organization.admin.index'))->assertForbidden();
});

it('allows owners and admins but not staff to access administration', function () {
    adminFixture('owner');
    $this->get(route('organization.admin.index'))->assertOk();
    adminFixture('admin');
    $this->get(route('organization.admin.index'))->assertOk();
    adminFixture('staff');
    $this->get(route('organization.admin.index'))->assertForbidden();
});

it('loads facilities and staff belonging only to the active organization', function () {
    [$user, $first] = adminFixture();
    $facility = Facility::factory()->create();
    $second = Organization::factory()->create();
    DB::table('facilities')->insert(['organization_id' => $second->id, 'name' => 'Private', 'code' => 'P', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $outsider = User::factory()->create();
    $outsider->organizations()->attach($second);
    $this->get(route('organization.admin.index'))->assertInertia(fn (Assert $page) => $page
        ->component('organizations/Admin')->where('organization.id', $first->id)
        ->has('facilities', 1)->where('facilities.0.id', $facility->id)
        ->has('members', 1)->where('members.0.id', $user->id));
});

it('updates organization settings for admins and rejects staff', function () {
    [, $org] = adminFixture();
    $this->patch(route('organization.admin.update'), ['name' => 'New name', 'timezone' => 'America/Chicago'])->assertRedirect();
    expect($org->fresh()->name)->toBe('New name');
    adminFixture('staff');
    $this->patch(route('organization.admin.update'), ['name' => 'Tamper', 'timezone' => 'UTC'])->assertForbidden();
    expect($org->fresh()->name)->toBe('New name');
});

it('validates organization input', function () {
    adminFixture();
    $this->patch(route('organization.admin.update'), ['name' => '', 'timezone' => 'bad-zone'])->assertSessionHasErrors(['name', 'timezone']);
});

it('creates facilities only in the selected organization', function () {
    [, $org] = adminFixture();
    $other = Organization::factory()->create();
    $this->post(route('organization.admin.facilities.store'), facilityPayload() + ['organization_id' => $other->id])->assertRedirect();
    expect(Facility::first()->organization_id)->toBe($org->id);
});

it('validates duplicate facility codes per organization', function () {
    adminFixture();
    Facility::factory()->create(['code' => 'MAIN']);
    $this->post(route('organization.admin.facilities.store'), facilityPayload())->assertSessionHasErrors('code');
    expect(Facility::count())->toBe(1);
});

it('edits and deletes facilities within the selected organization', function () {
    adminFixture();
    $facility = Facility::factory()->create();
    $this->patch(route('organization.admin.facilities.update', $facility->id), facilityPayload('NEW'))->assertRedirect();
    expect($facility->fresh()->code)->toBe('NEW');
    $this->delete(route('organization.admin.facilities.destroy', $facility->id))->assertRedirect();
    expect(Facility::count())->toBe(0);
});

it('returns 404 for facility ids owned by other organizations', function () {
    adminFixture();
    $other = Organization::factory()->create();
    $id = DB::table('facilities')->insertGetId(['organization_id' => $other->id, 'name' => 'Hidden', 'code' => 'H', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $this->patch(route('organization.admin.facilities.update', $id), facilityPayload())->assertNotFound();
    $this->delete(route('organization.admin.facilities.destroy', $id))->assertNotFound();
    expect(DB::table('facilities')->where('id', $id)->value('name'))->toBe('Hidden');
});

it('blocks a former admin after their membership is deactivated', function () {
    [$user, $org] = adminFixture();
    $user->organizations()->updateExistingPivot($org->id, ['status' => 'inactive']);
    $this->get(route('organization.admin.index'))->assertForbidden();
    $this->post(route('organization.admin.facilities.store'), facilityPayload())->assertForbidden();
});
