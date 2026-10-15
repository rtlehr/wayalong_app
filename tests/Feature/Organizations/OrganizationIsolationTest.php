<?php

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class OrganizationIsolationRecord extends Model
{
    use BelongsToOrganization;

    protected $table = 'isolation_test_records';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('isolation_test_records', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained();
        $table->string('title');
        $table->timestamps();
    });

    Route::middleware(['web', 'auth', 'organization.required'])
        ->get('/_test/organization-protected', fn () => response()->json(['allowed' => true]));
});

it('denies an organization-protected route to users without an active membership', function () {
    $this->actingAs(User::factory()->create())->get('/_test/organization-protected')->assertForbidden();
});

it('permits a protected route to an active member', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $user->organizations()->attach($organization);

    $this->actingAs($user)->get('/_test/organization-protected')->assertOk();
});

it('scopes all record queries to the active organization and changes scope after switching', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user->organizations()->attach([$first->id, $second->id]);
    DB::table('isolation_test_records')->insert([
        ['organization_id' => $first->id, 'title' => 'first'],
        ['organization_id' => $second->id, 'title' => 'second'],
    ]);

    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $first->id]);
    expect(OrganizationIsolationRecord::pluck('title')->all())->toBe(['first']);
    session()->put(OrganizationContext::SESSION_KEY, $second->id);
    expect(OrganizationIsolationRecord::pluck('title')->all())->toBe(['second']);
});

it('sets ownership on creation and blocks forged organization ids', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user->organizations()->attach($first);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $first->id]);

    $created = OrganizationIsolationRecord::create(['title' => 'owned']);
    expect($created->organization_id)->toBe($first->id);

    expect(fn () => OrganizationIsolationRecord::create(['title' => 'forged', 'organization_id' => $second->id]))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('prevents editing and deleting records after switching organizations', function () {
    $user = User::factory()->create();
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $user->organizations()->attach([$first->id, $second->id]);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $first->id]);
    $record = OrganizationIsolationRecord::create(['title' => 'original']);
    session()->put(OrganizationContext::SESSION_KEY, $second->id);

    expect(OrganizationIsolationRecord::find($record->id))->toBeNull();
    expect(fn () => $record->update(['title' => 'changed']))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    expect(fn () => $record->delete())->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('does not expose records when the membership is deactivated', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $user->organizations()->attach($organization);
    $this->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    OrganizationIsolationRecord::create(['title' => 'private']);
    $user->organizations()->updateExistingPivot($organization->id, ['status' => 'inactive']);

    expect(OrganizationIsolationRecord::count())->toBe(0);
    $this->get('/_test/organization-protected')->assertForbidden();
});
