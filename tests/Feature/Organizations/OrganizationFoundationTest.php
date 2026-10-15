<?php

use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates an organization with default status and timezone', function () {
    $organization = Organization::create([
        'name' => 'Example Treatment Center',
        'slug' => 'example-treatment-center',
    ]);

    expect($organization->fresh()->name)->toBe('Example Treatment Center')
        ->and($organization->fresh()->status)->toBe('active')
        ->and($organization->fresh()->timezone)->toBe('America/New_York');
});

it('creates independent organizations using the factory', function () {
    $organizations = Organization::factory()->count(3)->create();

    expect($organizations)->toHaveCount(3)
        ->and($organizations->pluck('slug')->unique())->toHaveCount(3);
});

it('enforces unique organization slugs at the database level', function () {
    Organization::factory()->create(['slug' => 'same-slug']);

    expect(fn () => Organization::factory()->create(['slug' => 'same-slug']))
        ->toThrow(QueryException::class);
});

it('provides the organization foundation columns', function () {
    expect(Schema::hasColumns('organizations', [
        'id', 'name', 'slug', 'status', 'timezone', 'created_at', 'updated_at',
    ]))->toBeTrue();
});
