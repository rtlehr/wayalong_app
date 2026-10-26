<?php

use App\Models\ClientAlert;
use App\Models\ClientEnrollment;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->withoutVite());

function alertSetup(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id, 'facility_id' => $facility->id,
        'user_id' => $user->id, 'role' => 'clinician', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $createClient = function (string $name) use ($facility): ClientEnrollment {
        $person = Person::create(['first_name' => $name, 'last_name' => 'Demo', 'person_type' => 'client', 'facility_id' => $facility->id]);
        return ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'status' => 'active', 'enrolled_at' => today()]);
    };
    $assigned = $createClient('Assigned');
    $unassigned = $createClient('Unassigned');
    DB::table('client_staff_assignments')->insert([
        'organization_id' => $organization->id, 'client_enrollment_id' => $assigned->id,
        'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    return [$organization, $user, $facility, $assigned, $unassigned];
}

it('shows only active assigned alerts in priority order and encrypts details', function () {
    [, , , $assigned] = alertSetup();
    $path = route('facility.clients.alerts.store', $assigned);
    $this->post($path, ['category' => 'general', 'priority' => 'follow_up', 'title' => 'Routine reminder'])->assertRedirect();
    $this->post($path, ['category' => 'medical', 'priority' => 'high', 'title' => 'Clinical attention needed', 'details' => 'Private test detail'])->assertRedirect();
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->has('myClients', 1)
        ->where('myClients.0.alerts.0.priority', 'high')
        ->where('myClients.0.alerts.0.details', 'Private test detail')
        ->where('myClients.0.alerts.1.priority', 'follow_up')->etc());
    expect(DB::table('client_alerts')->where('priority', 'high')->value('details'))->not->toContain('Private test detail');
    $alert = ClientAlert::where('priority', 'high')->firstOrFail();
    $this->patch(route('facility.clients.alerts.resolve', [$assigned, $alert]), [])->assertSessionHasErrors('reason');
    expect($alert->fresh()->resolved_at)->toBeNull();
    $this->patch(route('facility.clients.alerts.resolve', [$assigned, $alert]), ['reason' => 'Reviewed by clinical team; monitoring no longer required.'])->assertRedirect();
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->where('myClients.0.alerts.0.priority', 'follow_up')->has('myClients.0.alerts', 1)->etc());
    expect($alert->fresh()->resolved_by)->not->toBeNull();
    expect(\App\Models\ClientAlertEvent::where('client_alert_id', $alert->id)->pluck('action')->all())->toBe(['created', 'resolved']);
    $reason = 'Reviewed by clinical team; monitoring no longer required.';
    expect(\App\Models\ClientAlertEvent::where('client_alert_id', $alert->id)->where('action', 'resolved')->firstOrFail()->reason)->toBe($reason);
    expect(DB::table('client_alert_events')->where('client_alert_id', $alert->id)->where('action', 'resolved')->value('reason'))->not->toContain($reason);
    $this->get(route('facility.clients.treatment.alerts', $assigned))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('facilities/ClientTreatment')->where('alerts.0.events.1.action', 'resolved')
        ->where('alerts.0.events.1.reason', $reason)
        ->where('alerts.0.events.1.actor_name', auth()->user()->name)->etc());
});

it('rejects alert changes for clients not assigned to the clinician', function () {
    [, , , $assigned, $unassigned] = alertSetup();
    $this->post(route('facility.clients.alerts.store', $unassigned), ['category' => 'general', 'priority' => 'high', 'title' => 'Not permitted'])->assertForbidden();
    $this->post(route('facility.clients.alerts.store', $assigned), ['category' => 'general', 'priority' => 'high', 'title' => 'Permitted'])->assertRedirect();
    $alert = ClientAlert::firstOrFail();
    $this->patch(route('facility.clients.alerts.resolve', [$unassigned, $alert]), ['reason' => 'Should be forbidden'])->assertForbidden();
    expect($alert->fresh()->resolved_at)->toBeNull();
});

it('rejects invalid alert categories and priorities', function () {
    [, , , $assigned] = alertSetup();
    $this->post(route('facility.clients.alerts.store', $assigned), ['category' => 'billing', 'priority' => 'emergency', 'title' => 'Invalid'])
        ->assertSessionHasErrors(['category', 'priority']);
    expect(ClientAlert::count())->toBe(0);
});

it('does not permit resolving an alert twice or erase its original history', function () {
    [, , , $assigned] = alertSetup();
    $this->post(route('facility.clients.alerts.store', $assigned), [
        'category' => 'behavioral', 'priority' => 'warning', 'title' => 'Staff handoff needed',
    ])->assertRedirect();
    $alert = ClientAlert::firstOrFail();
    $url = route('facility.clients.alerts.resolve', [$assigned, $alert]);
    $this->patch($url, ['reason' => 'Reviewed and addressed'])->assertRedirect();
    $this->patch($url, ['reason' => 'Attempt to overwrite'])->assertStatus(422);
    expect(\App\Models\ClientAlertEvent::where('client_alert_id', $alert->id)->count())->toBe(2);
    expect(\App\Models\ClientAlertEvent::where('client_alert_id', $alert->id)->where('action', 'resolved')->firstOrFail()->reason)->toBe('Reviewed and addressed');
});

it('keeps resolved alert details off the quick view and protects the treatment history route', function () {
    [, , , $assigned, $unassigned] = alertSetup();
    $this->post(route('facility.clients.alerts.store', $assigned), [
        'category' => 'medical', 'priority' => 'high', 'title' => 'Resolved concern',
    ])->assertRedirect();
    $alert = ClientAlert::firstOrFail();
    $this->patch(route('facility.clients.alerts.resolve', [$assigned, $alert]), [
        'reason' => 'Reviewed and no longer active',
    ])->assertRedirect();
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->has('myClients.0.alerts', 0)
        ->missing('myClients.0.alertHistory')->etc());
    $this->get(route('facility.clients.treatment.alerts', $assigned))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('facilities/ClientTreatment')->has('alerts', 1)
        ->where('alerts.0.events.1.reason', 'Reviewed and no longer active')->etc());
    $this->get(route('facility.clients.treatment.alerts', $unassigned))->assertForbidden();
});

it('caps quick view at five active alerts and retains all on treatment page', function () {
    [, , , $assigned] = alertSetup();
    for ($i = 1; $i <= 7; $i++) {
        $this->post(route('facility.clients.alerts.store', $assigned), [
            'category' => 'general', 'priority' => 'warning', 'title' => 'Concern '.$i,
        ])->assertRedirect();
    }
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->where('myClients.0.activeAlertCount', 7)
        ->has('myClients.0.alerts', 5)->etc());
    $this->get(route('facility.clients.treatment.alerts', $assigned))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('facilities/ClientTreatment')->has('alerts', 7)->etc());
});

it('records alert progress updates and encrypted field-change history without overwriting past events', function () {
    [, , , $assigned] = alertSetup();
    $this->post(route('facility.clients.alerts.store', $assigned), [
        'category' => 'behavioral', 'priority' => 'high', 'title' => 'Evening transitions',
        'details' => 'Needs extra support',
    ])->assertRedirect();
    $alert = ClientAlert::firstOrFail();
    $url = route('facility.clients.alerts.update', [$assigned, $alert]);
    $this->patch($url, ['reason' => ''])->assertSessionHasErrors('reason');
    $this->patch($url, [
        'reason' => 'Improvement documented during evening shift',
        'priority' => 'warning', 'details' => 'Responding well to interventions',
    ])->assertRedirect();
    $this->patch($url, ['reason' => 'Continued observation; no additional incidents'])->assertRedirect();
    expect($alert->fresh()->priority)->toBe('warning');
    expect($alert->fresh()->details)->toBe('Responding well to interventions');
    $events = \App\Models\ClientAlertEvent::where('client_alert_id', $alert->id)->orderBy('id')->get();
    expect($events->pluck('action')->all())->toBe(['created', 'updated', 'updated']);
    expect($events[1]->reason)->toBe('Improvement documented during evening shift');
    expect($events[1]->changes['priority'])->toBe(['from' => 'high', 'to' => 'warning']);
    expect($events[1]->changes['details']['from'])->toBe('Needs extra support');
    expect($events[2]->changes)->toBeNull();
    expect(DB::table('client_alert_events')->where('id', $events[1]->id)->value('reason'))->not->toContain('Improvement documented');
    expect(DB::table('client_alert_events')->where('id', $events[1]->id)->value('changes'))->not->toContain('Needs extra support');
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->where('myClients.0.alerts.0.priority', 'warning')
        ->where('myClients.0.alerts.0.details', 'Responding well to interventions')
        ->where('myClients.0.alerts.0.title', 'Evening transitions')->etc());
    $this->get(route('facility.clients.treatment.alerts', $assigned))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('facilities/ClientTreatment')->where('alerts.0.events.1.action', 'updated')
        ->where('alerts.0.events.1.changes.priority.to', 'warning')
        ->where('alerts.0.events.2.reason', 'Continued observation; no additional incidents')->etc());
});

it('shows original alert and only the latest progress note in quick view', function () {
    [, , , $assigned] = alertSetup();
    $this->post(route('facility.clients.alerts.store', $assigned), [
        'category' => 'behavioral', 'priority' => 'high', 'title' => 'Original concern',
        'details' => 'Original sensitive context',
    ])->assertRedirect();
    $alert = ClientAlert::firstOrFail();
    $this->patch(route('facility.clients.alerts.update', [$assigned, $alert]), [
        'reason' => 'First progress note', 'title' => 'Revised instructions',
        'details' => 'New working instructions',
    ])->assertRedirect();
    $this->patch(route('facility.clients.alerts.update', [$assigned, $alert]), [
        'reason' => 'Most recent observation',
    ])->assertRedirect();

    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('myClients.0.alerts.0.title', 'Revised instructions')
        ->where('myClients.0.alerts.0.original.title', 'Original concern')
        ->where('myClients.0.alerts.0.original.details', 'Original sensitive context')
        ->where('myClients.0.alerts.0.latest_update.reason', 'Most recent observation')
        ->missing('myClients.0.alerts.0.events')
        ->etc());
    $this->get(route('facility.clients.treatment.alerts', $assigned))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('facilities/ClientTreatment')
        ->where('alerts.0.events.1.reason', 'First progress note')
        ->where('alerts.0.events.2.reason', 'Most recent observation')
        ->etc());
    expect(DB::table('client_alert_events')->where('client_alert_id', $alert->id)->where('action', 'created')->value('changes'))
        ->not->toContain('Original sensitive context');
});

it('reconstructs original alert details for alerts created before snapshots existed', function () {
    [, , , $assigned] = alertSetup();
    $this->post(route('facility.clients.alerts.store', $assigned), [
        'category' => 'general', 'priority' => 'warning', 'title' => 'Prior original', 'details' => 'Prior detail',
    ])->assertRedirect();
    $alert = ClientAlert::firstOrFail();
    $alert->events()->where('action', 'created')->firstOrFail()->update(['changes' => null]);
    $this->patch(route('facility.clients.alerts.update', [$assigned, $alert]), [
        'reason' => 'Updated the client instructions', 'title' => 'New title', 'details' => 'New detail',
    ])->assertRedirect();
    $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('myClients.0.alerts.0.original.title', 'Prior original')
        ->where('myClients.0.alerts.0.original.details', 'Prior detail')->etc());
});

it('prevents updates to resolved alerts and alerts belonging to unassigned clients', function () {
    [, , , $assigned, $unassigned] = alertSetup();
    $this->post(route('facility.clients.alerts.store', $assigned), [
        'category' => 'general', 'priority' => 'follow_up', 'title' => 'Check-in needed',
    ])->assertRedirect();
    $alert = ClientAlert::firstOrFail();
    $this->patch(route('facility.clients.alerts.update', [$unassigned, $alert]), [
        'reason' => 'Unauthorized attempt', 'priority' => 'high',
    ])->assertForbidden();
    $this->patch(route('facility.clients.alerts.resolve', [$assigned, $alert]), [
        'reason' => 'No further action needed',
    ])->assertRedirect();
    $this->patch(route('facility.clients.alerts.update', [$assigned, $alert]), [
        'reason' => 'Attempt after resolution', 'priority' => 'high',
    ])->assertStatus(422);
    expect($alert->fresh()->priority)->toBe('follow_up');
    expect(\App\Models\ClientAlertEvent::where('client_alert_id', $alert->id)->pluck('action')->all())->toBe(['created', 'resolved']);
});
