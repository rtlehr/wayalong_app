<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function phaseOneAdmin(Organization $organization, string $role = 'owner'): User
{
    $user = User::factory()->create();
    $user->organizations()->attach($organization, ['role' => $role]);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);

    return $user;
}

function phaseOneFacilityInput(string $code): array
{
    return ['name' => 'Treatment Center', 'code' => $code, 'status' => 'active'];
}

it('revokes administration immediately when an owner is downgraded to staff', function () {
    $organization = Organization::factory()->create();
    $user = phaseOneAdmin($organization);

    $this->get(route('organization.admin.index'))->assertOk();
    $user->organizations()->updateExistingPivot($organization->id, ['role' => 'staff']);

    $this->get(route('organization.admin.index'))->assertForbidden();
    $this->patch(route('organization.admin.update'), ['name' => 'Unauthorized', 'timezone' => 'UTC'])->assertForbidden();
    $this->post(route('organization.admin.facilities.store'), phaseOneFacilityInput('DENIED'))->assertForbidden();

    expect($organization->fresh()->name)->not->toBe('Unauthorized');
    expect(DB::table('facilities')->where('organization_id', $organization->id)->count())->toBe(0);
});

it('blocks all administration when the organization becomes inactive', function () {
    $organization = Organization::factory()->create();
    phaseOneAdmin($organization);
    $organization->update(['status' => 'inactive']);

    $this->get(route('organization.admin.index'))->assertForbidden();
    $this->post(route('organization.admin.facilities.store'), phaseOneFacilityInput('DENIED'))->assertForbidden();
    expect(Facility::count())->toBe(0);
});

it('cannot access another organization facility after switching, even as an admin of both', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user = phaseOneAdmin($first);
    $user->organizations()->attach($second, ['role' => 'admin']);
    $original = Facility::create(phaseOneFacilityInput('FIRST'));

    $this->post(route('organizations.switch'), ['organization_id' => $second->id])->assertRedirect();
    $this->patch(route('organization.admin.facilities.update', $original->id), phaseOneFacilityInput('TAMPER'))->assertNotFound();
    $this->delete(route('organization.admin.facilities.destroy', $original->id))->assertNotFound();
    $this->post(route('organization.admin.facilities.store'), phaseOneFacilityInput('SECOND'))->assertRedirect();

    expect(DB::table('facilities')->where('id', $original->id)->value('code'))->toBe('FIRST');
    expect(DB::table('facilities')->where('code', 'SECOND')->value('organization_id'))->toBe($second->id);
});

it('revokes writes to a previously loaded facility when active membership is deactivated', function () {
    $organization = Organization::factory()->create();
    $user = phaseOneAdmin($organization);
    $facility = Facility::create(phaseOneFacilityInput('SAFE'));

    $user->organizations()->updateExistingPivot($organization->id, ['status' => 'inactive']);

    expect(fn () => $facility->update(['name' => 'Changed']))->toThrow(AuthorizationException::class);
    expect(fn () => $facility->delete())->toThrow(AuthorizationException::class);
    expect(DB::table('facilities')->where('id', $facility->id)->value('name'))->toBe('Treatment Center');
});

it('does not allow bulk updates or deletes to affect rows belonging to another organization', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    phaseOneAdmin($first);
    Facility::create(phaseOneFacilityInput('FIRST'));
    DB::table('facilities')->insert([
        'organization_id' => $second->id,
        'name' => 'Private Center',
        'code' => 'SECOND',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Facility::query()->update(['name' => 'Updated']))->toBe(1);
    expect(Facility::query()->delete())->toBe(1);
    expect(DB::table('facilities')->where('organization_id', $second->id)->value('name'))->toBe('Private Center');
});
