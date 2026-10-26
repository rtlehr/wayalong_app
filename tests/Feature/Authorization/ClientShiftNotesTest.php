<?php

use App\Models\ClientEnrollment;
use App\Models\ClientShiftNote;
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

function shiftNoteSetup(): array
{
    $organization = Organization::factory()->create();
    $author = User::factory()->create();
    $reader = User::factory()->create();
    $organization->users()->attach($author, ['role' => 'staff', 'status' => 'active']);
    $organization->users()->attach($reader, ['role' => 'staff', 'status' => 'active']);
    // Organization-owned model factories need the active organization context.
    test()->actingAs($author)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    foreach ([[$author, 'clinician'], [$reader, 'floor_staff']] as [$user, $role]) {
        DB::table('organization_staff_facilities')->insert([
            'organization_id' => $organization->id,
            'facility_id' => $facility->id,
            'user_id' => $user->id,
            'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $create = function (string $name) use ($facility): ClientEnrollment {
        $person = Person::create(['first_name' => $name, 'last_name' => 'Demo', 'person_type' => 'client', 'facility_id' => $facility->id]);
        return ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'status' => 'active', 'enrolled_at' => today()]);
    };
    $assigned = $create('Assigned');
    $unassigned = $create('Unassigned');
    foreach ([$author, $reader] as $user) {
        DB::table('client_staff_assignments')->insert([
            'organization_id' => $organization->id,
            'client_enrollment_id' => $assigned->id,
            'user_id' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    return [$organization, $author, $reader, $assigned, $unassigned];
}

it('stores encrypted immutable shift documentation and displays the newest note in quick view', function () {
    [$organization, $author, , $assigned] = shiftNoteSetup();
    $this->post(route('facility.clients.treatment.shift-notes.store', $assigned), [
        'shift_date' => today()->toDateString(), 'shift' => 'evening',
        'summary' => 'Private shift summary', 'observations' => 'Sensitive observation',
        'significant_events' => 'Private event', 'interventions' => 'Staff response', 'handoff' => 'Review at next shift',
    ])->assertRedirect();
    expect(ClientShiftNote::count())->toBe(1);
    expect(ClientShiftNote::firstOrFail()->summary)->toBe('Private shift summary');
    expect(DB::table('client_shift_notes')->value('summary'))->not->toContain('Private shift summary');
    expect(DB::table('client_shift_notes')->value('handoff'))->not->toContain('Review at next shift');
    $this->get(route('facility.clients.treatment.shift-notes', $assigned))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('facilities/ClientShiftNotes')
            ->where('notes.data.0.summary', 'Private shift summary')->where('notes.data.0.author_name', $author->name)->where('notes.data.0.handoff', 'Review at next shift')->etc());
    expect($assigned->fresh()->latestShiftNote?->summary)->toBe('Private shift summary');
    // Match the organization-scoped dashboard tests: explicitly select the
    // organization after the prior POST/GET requests before reading My Clients.
    $this->actingAs($author)->withSession([OrganizationContext::SESSION_KEY => $organization->id])
        ->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('myClients', 1)
            ->where('myClients.0.id', $assigned->id)
            ->where('myClients.0.latestShiftNote.summary', 'Private shift summary')
            ->where('myClients.0.latestShiftNote.author_name', $author->name)
            ->etc());
});

it('restricts shift note creation and reading to facility-authorized assigned clients', function () {
    [, $author, $reader, $assigned, $unassigned] = shiftNoteSetup();
    $payload = ['shift_date' => today()->toDateString(), 'shift' => 'day', 'summary' => 'A valid note'];
    $this->post(route('facility.clients.treatment.shift-notes.store', $unassigned), $payload)->assertForbidden();
    $this->get(route('facility.clients.treatment.shift-notes', $unassigned))->assertForbidden();
    $this->post(route('facility.clients.treatment.shift-notes.store', $assigned), $payload)->assertRedirect();
    $this->actingAs($reader)->get(route('facility.clients.treatment.shift-notes', $assigned))->assertOk();
    $this->actingAs($reader)->post(route('facility.clients.treatment.shift-notes.store', $assigned), $payload)->assertRedirect();
    expect(ClientShiftNote::count())->toBe(2);
});

it('requires meaningful notes and valid shift dates', function () {
    [, , , $assigned] = shiftNoteSetup();
    $this->post(route('facility.clients.treatment.shift-notes.store', $assigned), [
        'shift_date' => today()->addDays(2)->toDateString(), 'shift' => 'invalid', 'summary' => 'No',
    ])->assertSessionHasErrors(['shift_date', 'shift', 'summary']);
    expect(ClientShiftNote::count())->toBe(0);
});

it('permits another assigned staff member to acknowledge a note once and blocks self-acknowledgment', function () {
    [, $author, $reader, $assigned, $unassigned] = shiftNoteSetup();
    $this->post(route('facility.clients.treatment.shift-notes.store', $assigned), [
        'shift_date' => today()->toDateString(), 'shift' => 'overnight', 'summary' => 'Handoff to incoming staff',
    ])->assertRedirect();
    $note = ClientShiftNote::firstOrFail();
    $url = route('facility.clients.treatment.shift-notes.acknowledge', [$assigned, $note]);
    $this->post($url)->assertStatus(422);
    $this->actingAs($reader)->post($url)->assertRedirect();
    $this->post($url)->assertRedirect();
    expect(DB::table('client_shift_note_acknowledgments')->count())->toBe(1);
    expect(DB::table('client_shift_note_acknowledgments')->value('user_id'))->toBe($reader->id);
    $this->get(route('facility.clients.treatment.shift-notes', $assigned))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('facilities/ClientShiftNotes')
        ->where('notes.data.0.author_name', $author->name)
        ->where('notes.data.0.acknowledgments.0.user_name', $reader->name)->etc());
    $this->post(route('facility.clients.treatment.shift-notes.acknowledge', [$unassigned, $note]))->assertForbidden();
});
