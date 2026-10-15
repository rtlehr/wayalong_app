<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('provides membership columns and foreign keys', function () {
    expect(Schema::hasColumns('organization_user', [
        'id', 'organization_id', 'user_id', 'role', 'status', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('allows a user to belong to multiple organizations with independent roles', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();

    $user->organizations()->attach($first->id, ['role' => 'admin']);
    $user->organizations()->attach($second->id, ['role' => 'clinician']);

    expect($user->fresh()->organizations)->toHaveCount(2)
        ->and($user->fresh()->organizations->find($first->id)->pivot->role)->toBe('admin')
        ->and($user->fresh()->organizations->find($second->id)->pivot->role)->toBe('clinician')
        ->and($first->fresh()->users->first()->id)->toBe($user->id);
});

it('defaults membership role and status when not supplied', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    $user->organizations()->attach($organization);

    expect($user->fresh()->organizations->first()->pivot->role)->toBe('staff')
        ->and($user->fresh()->organizations->first()->pivot->status)->toBe('active');
});

it('keeps memberships and statuses independent between organizations', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();

    $user->organizations()->attach($first, ['status' => 'inactive']);
    $user->organizations()->attach($second, ['status' => 'active']);

    expect($user->fresh()->organizations->find($first->id)->pivot->status)->toBe('inactive')
        ->and($user->fresh()->organizations->find($second->id)->pivot->status)->toBe('active');
});

it('prevents duplicate memberships at the database level', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $user->organizations()->attach($organization);

    expect(fn () => $user->organizations()->attach($organization))
        ->toThrow(QueryException::class);
});

it('deletes memberships when an organization is deleted without deleting the user', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $user->organizations()->attach($organization);

    $organization->delete();

    expect(DB::table('organization_user')->where('user_id', $user->id)->count())->toBe(0)
        ->and(User::find($user->id))->not->toBeNull();
});

it('deletes memberships when a user is deleted without deleting the organization', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $user->organizations()->attach($organization);

    $user->delete();

    expect(DB::table('organization_user')->where('organization_id', $organization->id)->count())->toBe(0)
        ->and(Organization::find($organization->id))->not->toBeNull();
});
