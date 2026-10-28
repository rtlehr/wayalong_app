<?php

use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Models\User;
use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->withoutVite());

function auditViewerActor(Organization $organization, string $role = 'staff'): User
{
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => $role, 'status' => 'active']);

    return $user;
}

function auditViewerEvent(Organization $organization, User $user, int $enrollment = 100, string $section = 'alerts', string $outcome = 'viewed'): void
{
    DB::table('clinical_access_events')->insert([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'client_enrollment_id' => $enrollment,
        'section' => $section,
        'outcome' => $outcome,
        'created_at' => now(),
    ]);
}

it('grants organization owners organization scoped security audit access', function () {
    $organization = Organization::factory()->create();
    $owner = auditViewerActor($organization, 'owner');
    $this->get(route('admin.security.audit'))->assertRedirect();
    $this->actingAs($owner)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $this->get(route('admin.security.audit'))->assertOk();
    expect(app(OrganizationPermissions::class)->allows($owner, $organization, OrganizationPermissions::VIEW_SECURITY_AUDIT))->toBeTrue();
});

it('shows only current organization audit metadata and filters by staff, section and outcome', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();
    $auditor = auditViewerActor($organization);
    $role = OrganizationRole::create([
        'organization_id' => $organization->id, 'name' => 'Security Auditor',
        'permissions' => [OrganizationPermissions::VIEW_SECURITY_AUDIT],
    ]);
    $auditor->organizations()->updateExistingPivot($organization->id, ['role' => 'custom:'.$role->id]);
    $actor = auditViewerActor($organization);
    $otherActor = auditViewerActor($other);
    auditViewerEvent($organization, $actor, 41, 'alerts', 'denied');
    auditViewerEvent($organization, $actor, 42, 'overview', 'viewed');
    auditViewerEvent($other, $otherActor, 43, 'alerts', 'denied');

    $this->actingAs($auditor)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $this->get(route('admin.security.audit', ['user_id' => $actor->id, 'section' => 'alerts', 'outcome' => 'denied']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/SecurityAudit')
            ->has('events.data', 1)
            ->where('events.data.0.client_enrollment_id', 41)
            ->where('events.data.0.outcome', 'denied')
            ->missing('events.data.0.description')
            ->etc());
    $this->get(route('admin.command-center'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('tools', 1)->where('tools.0.key', 'security-audit')->etc());
});

it('refuses cross-organization audit viewing after membership or organization switching', function () {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $auditor = auditViewerActor($first);
    $role = OrganizationRole::create(['organization_id' => $first->id, 'name' => 'Auditor', 'permissions' => [OrganizationPermissions::VIEW_SECURITY_AUDIT]]);
    $auditor->organizations()->updateExistingPivot($first->id, ['role' => 'custom:'.$role->id]);
    $second->users()->attach($auditor, ['role' => 'staff', 'status' => 'active']);
    $this->actingAs($auditor)->withSession([OrganizationContext::SESSION_KEY => $first->id]);
    $this->get(route('admin.security.audit'))->assertOk();
    $this->withSession([OrganizationContext::SESSION_KEY => $second->id]);
    $this->get(route('admin.security.audit'))->assertForbidden();
});
