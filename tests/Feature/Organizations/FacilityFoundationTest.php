<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function selectFacilityOrganization(User $user, Organization $organization): void
{
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
}

it('creates a facilities table with ownership and contact fields', function () {
    expect(Schema::hasColumns('facilities', [
        'id', 'organization_id', 'name', 'code', 'status', 'address_line_1',
        'address_line_2', 'city', 'state', 'postal_code', 'phone', 'email',
        'timezone', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('creates multiple facilities under the selected organization using the factory', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $user->organizations()->attach($organization);
    selectFacilityOrganization($user, $organization);

    Facility::factory()->count(2)->create();

    expect($organization->facilities)->toHaveCount(2)
        ->and(Facility::count())->toBe(2)
        ->and(Facility::first()->status)->toBe('active');
});

it('permits the same facility code at different organizations but not twice in one organization', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user->organizations()->attach([$first->id, $second->id]);

    selectFacilityOrganization($user, $first);
    Facility::factory()->create(['code' => 'MAIN']);
    expect(fn () => Facility::factory()->create(['code' => 'MAIN']))->toThrow(QueryException::class);

    session()->put(OrganizationContext::SESSION_KEY, $second->id);
    Facility::factory()->create(['code' => 'MAIN']);
    expect(Facility::count())->toBe(1);
});

it('isolates facilities across organization switches and rejects forged ownership', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user->organizations()->attach([$first->id, $second->id]);
    selectFacilityOrganization($user, $first);

    $facility = Facility::factory()->create(['name' => 'Private facility']);
    expect($facility->organization_id)->toBe($first->id)
        ->and(fn () => Facility::factory()->create(['organization_id' => $second->id]))->toThrow(AuthorizationException::class);

    session()->put(OrganizationContext::SESSION_KEY, $second->id);
    expect(Facility::find($facility->id))->toBeNull()
        ->and(Facility::count())->toBe(0);
    expect(fn () => $facility->update(['name' => 'Tampered']))->toThrow(AuthorizationException::class);
    expect(fn () => $facility->delete())->toThrow(AuthorizationException::class);
});

it('denies facility access when membership is inactive', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $user->organizations()->attach($organization);
    selectFacilityOrganization($user, $organization);
    Facility::factory()->create();

    $user->organizations()->updateExistingPivot($organization->id, ['status' => 'inactive']);

    expect(Facility::count())->toBe(0);
    expect(fn () => Facility::factory()->create())->toThrow(AuthorizationException::class);
});

it('deletes child facilities when their organization is deleted', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $user->organizations()->attach($organization);
    selectFacilityOrganization($user, $organization);
    $facility = Facility::factory()->create();

    $organization->delete();

    expect(DB::table('facilities')->where('id', $facility->id)->exists())->toBeFalse();
});
