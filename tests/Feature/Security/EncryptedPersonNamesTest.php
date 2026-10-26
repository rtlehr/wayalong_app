<?php

use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use App\Support\OrganizationContext;
use App\Support\PersonNameSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('encrypts person names and retains partial searches with keyed tokens', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);

    $person = Person::create(['first_name' => 'Annalise', 'last_name' => 'Winchester', 'person_type' => 'client']);
    $raw = DB::table('people')->where('id', $person->id)->first();
    expect($raw->first_name)->not->toContain('Annalise')
        ->and($raw->last_name)->not->toContain('Winchester')
        ->and($person->fresh()->first_name)->toBe('Annalise')
        ->and($person->fresh()->last_name)->toBe('Winchester');
    $tokens = DB::table('person_name_search_tokens')->where('person_id', $person->id)->pluck('token');
    expect($tokens)->not->toBeEmpty()
        ->and($tokens->every(fn ($token) => preg_match('/^[0-9a-f]{64}$/D', $token) === 1))->toBeTrue();

    $this->get(route('organization.admin.people.index', ['search' => 'nali']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('people.data', 1)
            ->where('people.data.0.id', $person->id)->etc());
    $this->get(route('organization.admin.people.index', ['search' => 'chest']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('people.data', 1)->etc());

    $person->update(['first_name' => 'Beatrice']);
    $this->get(route('organization.admin.people.index', ['search' => 'nali']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('people.data', 0)->etc());
    $this->get(route('organization.admin.people.index', ['search' => 'eatr']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('people.data', 1)->etc());
});

it('does not include other organizations in encrypted name searches', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $owner = User::factory()->create();
    $first->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $first->id]);
    $person = Person::create(['first_name' => 'UniqueSecret', 'last_name' => 'Person', 'person_type' => 'client']);
    expect(PersonNameSearch::constrain(Person::query(), 'Secret', (int) $first->id)->count())->toBe(1);

    $this->withSession([OrganizationContext::SESSION_KEY => $second->id]);
    expect(PersonNameSearch::constrain(Person::query(), 'Secret', (int) $second->id)->count())->toBe(0);
    expect(DB::table('people')->where('id', $person->id)->value('first_name'))->not->toBe('UniqueSecret');
});
