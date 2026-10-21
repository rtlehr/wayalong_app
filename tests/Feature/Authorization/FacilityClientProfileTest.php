<?php

use App\Models\ClientEnrollment;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use App\Support\FacilityContext;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);
beforeEach(function () { $this->withoutVite(); });

function profileSetup(): array
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $org->id]);
    $facility = Facility::factory()->create(['organization_id' => $org->id]);
    DB::table('organization_staff_facilities')->insert(['organization_id' => $org->id, 'facility_id' => $facility->id, 'user_id' => $user->id, 'role' => 'clinician', 'created_at' => now(), 'updated_at' => now()]);
    test()->withSession([FacilityContext::SESSION_KEY => $facility->id]);
    $person = Person::create(['first_name' => 'Youth', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $facility->id]);
    $enrollment = ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString()]);

    return [$org, $user, $facility, $person, $enrollment];
}

it('shows a client profile and allows editing without a login or email', function () {
    [, , , $person, $enrollment] = profileSetup();
    $this->get(route('facility.clients.show', $enrollment))->assertOk()->assertInertia(fn (Assert $page) => $page->component('facilities/ClientProfile')->where('mode', 'view')->where('person.first_name', 'Youth')->etc());
    $this->get(route('facility.clients.edit', $enrollment))->assertOk()->assertInertia(fn (Assert $page) => $page->component('facilities/ClientProfile')->where('mode', 'edit')->etc());
    $this->patch(route('facility.clients.profile.update', $enrollment), ['first_name' => 'Updated', 'last_name' => 'Client', 'date_of_birth' => '2012-05-04', 'contacts' => [], 'guardian_ids' => [], 'staff_ids' => []])->assertRedirect(route('facility.clients.edit', $enrollment));
    expect($person->fresh()->first_name)->toBe('Updated')->and($person->fresh()->user_id)->toBeNull();
});

it('restricts profile access and editing to the currently authorized facility', function () {
    [$org, , $facility, , $enrollment] = profileSetup();
    $other = Facility::factory()->create(['organization_id' => $org->id]);
    $this->withSession([FacilityContext::SESSION_KEY => $other->id]);
    $this->get(route('facility.clients.show', $enrollment))->assertForbidden();
    $this->get(route('facility.clients.edit', $enrollment))->assertForbidden();
    $this->patch(route('facility.clients.profile.update', $enrollment), ['first_name' => 'Bad', 'last_name' => 'Update'])->assertForbidden();
    $this->withSession([FacilityContext::SESSION_KEY => $facility->id])->get(route('facility.clients.show', $enrollment))->assertOk();
});

it('links guardian person records and assigned staff without creating account access', function () {
    [, $user, $facility, , $enrollment] = profileSetup();
    $guardian = Person::create(['first_name' => 'Example', 'last_name' => 'Guardian', 'person_type' => 'guardian', 'facility_id' => $facility->id]);
    $this->patch(route('facility.clients.profile.update', $enrollment), ['first_name' => 'Youth', 'last_name' => 'Client', 'contacts' => [], 'guardian_ids' => [$guardian->id], 'staff_ids' => [$user->id]])->assertRedirect();
    $this->assertDatabaseHas('client_guardian_relationships', ['client_enrollment_id' => $enrollment->id, 'guardian_person_id' => $guardian->id]);
    $this->assertDatabaseHas('client_staff_assignments', ['client_enrollment_id' => $enrollment->id, 'user_id' => $user->id]);
    expect($guardian->fresh()->user_id)->toBeNull();
});

it('rejects unrelated facility staff and invalid guardian associations', function () {
    [$org, , $facility, $person, $enrollment] = profileSetup();
    $otherStaff = User::factory()->create();
    $org->users()->attach($otherStaff, ['role' => 'staff', 'status' => 'active']);
    $this->patch(route('facility.clients.profile.update', $enrollment), ['first_name' => 'Changed', 'last_name' => 'Client', 'staff_ids' => [$otherStaff->id]])->assertSessionHasErrors('staff_ids');
    $this->patch(route('facility.clients.profile.update', $enrollment), ['first_name' => 'Changed', 'last_name' => 'Client', 'guardian_ids' => [$person->id]])->assertSessionHasErrors('guardian_ids.0');
    expect($person->fresh()->first_name)->toBe('Youth');
});

it('stores guardian relationship details without granting portal access', function () {
    [, , $facility, , $enrollment] = profileSetup();
    $guardian = Person::create(['first_name' => 'Taylor', 'last_name' => 'Guardian', 'person_type' => 'guardian', 'facility_id' => $facility->id]);
    $this->patch(route('facility.clients.profile.update', $enrollment), [
        'first_name' => 'Youth', 'last_name' => 'Client',
        'guardian_ids' => [$guardian->id],
        'guardian_details' => [(string) $guardian->id => [
            'relationship_label' => 'Foster parent',
            'is_emergency_contact' => true,
            'notes' => 'Contact through facility office.',
        ]],
        'staff_ids' => [], 'contacts' => [],
    ])->assertRedirect(route('facility.clients.edit', $enrollment));
    $this->assertDatabaseHas('client_guardian_relationships', [
        'client_enrollment_id' => $enrollment->id,
        'guardian_person_id' => $guardian->id,
        'relationship_label' => 'Foster parent',
        'is_emergency_contact' => 1,
    ]);
    $this->get(route('facility.clients.show', $enrollment))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('facilities/ClientProfile')
            ->where('guardians.0.relationship_label', 'Foster parent')
            ->where('guardians.0.is_emergency_contact', true)->etc());
    expect($guardian->fresh()->user_id)->toBeNull();
});

it('rejects guardian details for an unselected person', function () {
    [, , $facility, , $enrollment] = profileSetup();
    $guardian = Person::create(['first_name' => 'Taylor', 'last_name' => 'Guardian', 'person_type' => 'guardian', 'facility_id' => $facility->id]);
    $this->patch(route('facility.clients.profile.update', $enrollment), [
        'first_name' => 'Youth', 'last_name' => 'Client', 'guardian_ids' => [],
        'guardian_details' => [(string) $guardian->id => ['relationship_label' => 'Parent']],
    ])->assertSessionHasErrors('guardian_details');
    $this->assertDatabaseMissing('client_guardian_relationships', [
        'client_enrollment_id' => $enrollment->id, 'guardian_person_id' => $guardian->id,
    ]);
});

it('lets an authorized clinician create a guardian without an email and links the person to a client', function () {
    [$org, , $facility, , $enrollment] = profileSetup();
    $this->post(route('facility.clients.guardians.store', $enrollment), [
        'first_name' => 'Morgan', 'last_name' => 'Parent', 'relationship_label' => 'Parent',
        'is_emergency_contact' => true, 'phone' => '555-555-0188', 'address' => '55 Main St',
        'city' => 'Winchester', 'state' => 'VA', 'postal_code' => '22601',
    ])->assertRedirect(route('facility.clients.edit', $enrollment));
    $guardian = Person::query()->where('first_name', 'Morgan')->where('last_name', 'Parent')->firstOrFail();
    expect($guardian->organization_id)->toBe($org->id)
        ->and($guardian->user_id)->toBeNull()
        ->and($guardian->person_type)->toBe('guardian');
    $this->assertDatabaseHas('client_guardian_relationships', [
        'client_enrollment_id' => $enrollment->id, 'guardian_person_id' => $guardian->id,
        'relationship_label' => 'Parent', 'is_emergency_contact' => 1,
    ]);
    $this->get(route('facility.clients.show', $enrollment))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('guardians.0.contacts.0.value', '555-555-0188')->etc());
    $this->get(route('facility.clients.guardians.show', [$enrollment, $guardian]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('facilities/ClientGuardianProfile')->where('guardian.first_name', 'Morgan')->etc());
});

it('prevents browsing a guardian through a client they are not linked to', function () {
    [, , $facility, , $enrollment] = profileSetup();
    $unlinked = Person::create(['first_name' => 'Unlinked', 'last_name' => 'Guardian', 'person_type' => 'guardian', 'facility_id' => $facility->id]);
    $this->get(route('facility.clients.guardians.show', [$enrollment, $unlinked]))->assertNotFound();
});

it('blocks creating guardians when client management permissions are absent', function () {
    [$org, $user, $facility, , $enrollment] = profileSetup();
    DB::table('organization_staff_facilities')->where('organization_id', $org->id)->where('facility_id', $facility->id)->where('user_id', $user->id)->update(['role' => 'staff']);
    $this->post(route('facility.clients.guardians.store', $enrollment), [
        'first_name' => 'Denied', 'last_name' => 'Person', 'relationship_label' => 'Parent',
    ])->assertForbidden();
    $this->assertDatabaseMissing('people', ['first_name' => 'Denied', 'last_name' => 'Person']);
});

it('records guardian authorization and consent history without granting login access', function () {
    [$org, , $facility, , $enrollment] = profileSetup();
    $guardian = Person::create(['first_name' => 'Guardian', 'last_name' => 'Example', 'person_type' => 'guardian', 'facility_id' => $facility->id]);
    DB::table('client_guardian_relationships')->insert([
        'organization_id' => $org->id, 'client_enrollment_id' => $enrollment->id,
        'guardian_person_id' => $guardian->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $url = route('facility.clients.guardian-authorizations.store', $enrollment);
    $this->post($url, [
        'guardian_person_id' => $guardian->id, 'record_type' => 'authority',
        'subject' => 'legal_authority', 'decision' => 'verified',
        'effective_on' => today()->toDateString(), 'evidence_reference' => 'Case file 123',
    ])->assertRedirect(route('facility.clients.edit', $enrollment));
    $this->post($url, [
        'guardian_person_id' => $guardian->id, 'record_type' => 'consent',
        'subject' => 'treatment', 'decision' => 'withdrawn',
        'effective_on' => today()->toDateString(),
    ])->assertRedirect();
    $this->assertDatabaseCount('guardian_authorization_records', 2);
    $this->assertDatabaseHas('guardian_authorization_records', [
        'guardian_person_id' => $guardian->id, 'record_type' => 'authority',
        'decision' => 'verified', 'organization_id' => $org->id,
    ]);
    $this->get(route('facility.clients.show', $enrollment))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('authorizationRecords.0.decision', 'withdrawn')->where('authorizationRecords.1.decision', 'verified')->etc());
    expect($guardian->fresh()->user_id)->toBeNull();
});

it('rejects unlinked guardians and missing evidence for verified authorization', function () {
    [, , $facility, , $enrollment] = profileSetup();
    $unlinked = Person::create(['first_name' => 'Other', 'last_name' => 'Guardian', 'person_type' => 'guardian', 'facility_id' => $facility->id]);
    $url = route('facility.clients.guardian-authorizations.store', $enrollment);
    $request = [
        'guardian_person_id' => $unlinked->id, 'record_type' => 'authority',
        'subject' => 'legal_authority', 'decision' => 'verified',
        'effective_on' => today()->toDateString(), 'evidence_reference' => 'Proof',
    ];
    $this->post($url, $request)->assertNotFound();
    $this->assertDatabaseCount('guardian_authorization_records', 0);
    $request['evidence_reference'] = '';
    $this->post($url, $request)->assertSessionHasErrors('evidence_reference');
});

it('denies authorization record changes without facility client management permission', function () {
    [$org, $user, $facility, , $enrollment] = profileSetup();
    $guardian = Person::create(['first_name' => 'Example', 'last_name' => 'Parent', 'person_type' => 'guardian', 'facility_id' => $facility->id]);
    DB::table('client_guardian_relationships')->insert([
        'organization_id' => $org->id, 'client_enrollment_id' => $enrollment->id,
        'guardian_person_id' => $guardian->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('organization_staff_facilities')->where('organization_id', $org->id)
        ->where('facility_id', $facility->id)->where('user_id', $user->id)->update(['role' => 'staff']);
    $this->post(route('facility.clients.guardian-authorizations.store', $enrollment), [
        'guardian_person_id' => $guardian->id, 'record_type' => 'authority',
        'subject' => 'legal_authority', 'decision' => 'pending',
        'effective_on' => today()->toDateString(),
    ])->assertForbidden();
    $this->assertDatabaseCount('guardian_authorization_records', 0);
});

it('uploads a private shared document for an authorized client and downloads it', function () {
    [, , $facility, , $enrollment] = profileSetup();
    \Illuminate\Support\Facades\Storage::fake('local');
    $this->post(route('facility.clients.documents.store', $enrollment), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('custody.pdf', 10, 'application/pdf'),
        'title' => 'Custody order', 'category' => 'custody', 'expires_on' => '2026-12-31',
    ])->assertRedirect(route('facility.clients.edit', $enrollment));
    $document = \App\Models\Document::query()->sole();
    expect((int) $document->facility_id)->toBe((int) $facility->id);
    \Illuminate\Support\Facades\Storage::disk('local')->assertExists($document->storage_path);
    $this->assertDatabaseHas('document_attachments', [
        'document_id' => $document->id, 'record_type' => \App\Support\DocumentManager::CLIENT_ENROLLMENT,
        'record_id' => $enrollment->id,
    ]);
    $this->get(route('facility.clients.show', $enrollment))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('facilities/ClientProfile')
            ->where('documents.0.title', 'Custody order')->etc());
    $this->get(route('facility.clients.documents.download', [$enrollment, $document]))->assertOk();
});

it('blocks document access when selecting another facility and blocks unrelated attachments', function () {
    [$org, , $facility, , $enrollment] = profileSetup();
    \Illuminate\Support\Facades\Storage::fake('local');
    $this->post(route('facility.clients.documents.store', $enrollment), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('consent.pdf', 10, 'application/pdf'),
        'title' => 'Consent', 'category' => 'consent',
    ])->assertRedirect();
    $document = \App\Models\Document::query()->sole();
    $other = ClientEnrollment::create([
        'facility_id' => $facility->id,
        'person_id' => Person::create(['first_name' => 'Another', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $facility->id])->id,
        'enrolled_at' => today()->toDateString(),
    ]);
    $this->get(route('facility.clients.documents.download', [$other, $document]))->assertNotFound();
    $secondFacility = Facility::factory()->create(['organization_id' => $org->id]);
    $this->withSession([FacilityContext::SESSION_KEY => $secondFacility->id]);
    $this->get(route('facility.clients.documents.download', [$enrollment, $document]))->assertForbidden();
    $this->post(route('facility.clients.documents.store', $enrollment), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('attempt.pdf', 10, 'application/pdf'),
        'title' => 'Attempt', 'category' => 'consent',
    ])->assertForbidden();
});

it('rejects unsupported document types and does not expose a public document path', function () {
    [, , , , $enrollment] = profileSetup();
    \Illuminate\Support\Facades\Storage::fake('local');
    $this->post(route('facility.clients.documents.store', $enrollment), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream'),
        'title' => 'Unsafe', 'category' => 'other',
    ])->assertSessionHasErrors('file');
    $this->assertDatabaseCount('documents', 0);
});


it('accepts Word documents in both legacy and modern formats', function () {
    [, , , , $enrollment] = profileSetup();
    \Illuminate\Support\Facades\Storage::fake('local');

    foreach ([
        ['filename' => 'intake.doc', 'mime' => 'application/msword'],
        ['filename' => 'consent.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ] as $wordFile) {
        $this->post(route('facility.clients.documents.store', $enrollment), [
            'file' => \Illuminate\Http\UploadedFile::fake()->create($wordFile['filename'], 10, $wordFile['mime']),
            'title' => $wordFile['filename'],
            'category' => 'consent',
        ])->assertRedirect(route('facility.clients.edit', $enrollment));
    }

    $this->assertDatabaseCount('documents', 2);
});

it('records document reviews with an append-only review history', function () {
    [, , , , $enrollment] = profileSetup();
    \Illuminate\Support\Facades\Storage::fake('local');
    $this->post(route('facility.clients.documents.store', $enrollment), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('authority.pdf', 10, 'application/pdf'),
        'title' => 'Authority', 'category' => 'custody',
    ])->assertRedirect();
    $document = \App\Models\Document::query()->sole();
    $url = route('facility.clients.documents.review', [$enrollment, $document]);
    $this->post($url, ['status' => 'verified', 'notes' => 'Reviewed case file'])->assertRedirect();
    $this->post($url, ['status' => 'rejected', 'notes' => 'New information'])->assertRedirect();
    expect($document->fresh()->review_status)->toBe('rejected');
    $this->assertDatabaseCount('document_review_events', 2);
    $this->get(route('facility.clients.show', $enrollment))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('documents.0.review_status', 'rejected')->where('documents.0.review_events.0.status', 'rejected')
        ->where('documents.0.review_events.1.status', 'verified')->etc());
});

it('refuses to review documents for other enrollments or without manage permissions', function () {
    [$org, $user, $facility, , $enrollment] = profileSetup();
    \Illuminate\Support\Facades\Storage::fake('local');
    $this->post(route('facility.clients.documents.store', $enrollment), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('consent.pdf', 10, 'application/pdf'),
        'title' => 'Consent', 'category' => 'consent',
    ])->assertRedirect();
    $document = \App\Models\Document::query()->sole();
    $other = ClientEnrollment::create([
        'facility_id' => $facility->id,
        'person_id' => Person::create(['first_name' => 'Unrelated', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $facility->id])->id,
        'enrolled_at' => today()->toDateString(),
    ]);
    $this->post(route('facility.clients.documents.review', [$other, $document]), ['status' => 'verified'])->assertNotFound();
    DB::table('organization_staff_facilities')->where('organization_id', $org->id)
        ->where('facility_id', $facility->id)->where('user_id', $user->id)->update(['role' => 'staff']);
    $this->post(route('facility.clients.documents.review', [$enrollment, $document]), ['status' => 'verified'])->assertForbidden();
    $this->assertDatabaseCount('document_review_events', 0);
});

it('links only documents attached to the same client enrollment as consent evidence', function () {
    [$org, , $facility, , $enrollment] = profileSetup();
    \Illuminate\Support\Facades\Storage::fake('local');
    $guardian = Person::create(['first_name' => 'Example', 'last_name' => 'Guardian', 'person_type' => 'guardian', 'facility_id' => $facility->id]);
    DB::table('client_guardian_relationships')->insert([
        'organization_id' => $org->id, 'client_enrollment_id' => $enrollment->id,
        'guardian_person_id' => $guardian->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->post(route('facility.clients.documents.store', $enrollment), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
        'title' => 'Evidence', 'category' => 'consent',
    ])->assertRedirect();
    $document = \App\Models\Document::query()->sole();
    $payload = [
        'guardian_person_id' => $guardian->id, 'record_type' => 'consent', 'subject' => 'treatment',
        'decision' => 'granted', 'effective_on' => today()->toDateString(),
        'evidence_document_id' => $document->id,
    ];
    $this->post(route('facility.clients.guardian-authorizations.store', $enrollment), $payload)->assertRedirect();
    $this->assertDatabaseHas('guardian_authorization_records', [
        'client_enrollment_id' => $enrollment->id, 'evidence_document_id' => $document->id,
    ]);
    $other = ClientEnrollment::create([
        'facility_id' => $facility->id,
        'person_id' => Person::create(['first_name' => 'Other', 'last_name' => 'Youth', 'person_type' => 'client', 'facility_id' => $facility->id])->id,
        'enrolled_at' => today()->toDateString(),
    ]);
    $otherGuardian = Person::create(['first_name' => 'Other', 'last_name' => 'Parent', 'person_type' => 'guardian', 'facility_id' => $facility->id]);
    DB::table('client_guardian_relationships')->insert([
        'organization_id' => $org->id, 'client_enrollment_id' => $other->id,
        'guardian_person_id' => $otherGuardian->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $payload['guardian_person_id'] = $otherGuardian->id;
    $this->post(route('facility.clients.guardian-authorizations.store', $other), $payload)->assertNotFound();
    $this->assertDatabaseCount('guardian_authorization_records', 1);
});


it('records an audit event only for authorized document downloads', function () {
    [$org, $user, $facility, , $enrollment] = profileSetup();
    \Illuminate\Support\Facades\Storage::fake('local');
    $this->post(route('facility.clients.documents.store', $enrollment), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('audit.pdf', 10, 'application/pdf'),
        'title' => 'Audit', 'category' => 'other',
    ])->assertRedirect();
    $document = \App\Models\Document::query()->sole();
    $this->get(route('facility.clients.documents.download', [$enrollment, $document]))->assertOk();
    $this->assertDatabaseHas('document_access_events', [
        'document_id' => $document->id, 'user_id' => $user->id, 'action' => 'download',
        'record_type' => 'client_enrollment', 'record_id' => $enrollment->id,
    ]);

    $other = ClientEnrollment::create([
        'facility_id' => $facility->id,
        'person_id' => Person::create(['first_name' => 'Other', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $facility->id])->id,
        'enrolled_at' => today()->toDateString(),
    ]);
    $this->get(route('facility.clients.documents.download', [$other, $document]))->assertNotFound();
    $this->assertDatabaseCount('document_access_events', 1);
});

it('sends one expiration notice per window to authorized clinicians only', function () {
    [$org, $user, $facility, , $enrollment] = profileSetup();
    \Illuminate\Support\Facades\Storage::fake('local');
    $this->post(route('facility.clients.documents.store', $enrollment), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('notice.pdf', 10, 'application/pdf'),
        'title' => 'Private evidence', 'category' => 'consent',
        'expires_on' => today()->addDays(10)->toDateString(),
    ])->assertRedirect();
    $document = \App\Models\Document::query()->sole();
    $staff = User::factory()->create();
    $org->users()->attach($staff, ['role' => 'staff', 'status' => 'active']);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $org->id, 'facility_id' => $facility->id,
        'user_id' => $staff->id, 'role' => 'staff', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $notifier = app(\App\Support\DocumentExpirationNotifier::class);
    expect($notifier->send())->toBe(1);
    expect($notifier->send())->toBe(0);
    expect($user->notifications()->count())->toBe(1);
    expect($staff->notifications()->count())->toBe(0);
    $this->assertDatabaseCount('document_expiration_notices', 1);
    expect($user->notifications()->first()->data['message'])->not->toContain('Private evidence');

    $document->update(['expires_on' => today()->subDay()->toDateString()]);
    expect($notifier->send())->toBe(1);
    expect($notifier->send())->toBe(0);
    $this->assertDatabaseCount('document_expiration_notices', 2);
});

it('skips document expiration notices after facility permissions are revoked', function () {
    [$org, $user, $facility, , $enrollment] = profileSetup();
    \Illuminate\Support\Facades\Storage::fake('local');
    $this->post(route('facility.clients.documents.store', $enrollment), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('notice.pdf', 10, 'application/pdf'),
        'title' => 'Evidence', 'category' => 'consent',
        'expires_on' => today()->addDays(3)->toDateString(),
    ])->assertRedirect();
    DB::table('organization_staff_facilities')->where('organization_id', $org->id)
        ->where('facility_id', $facility->id)->where('user_id', $user->id)->update(['role' => 'staff']);
    expect(app(\App\Support\DocumentExpirationNotifier::class)->send())->toBe(0);
    $this->assertDatabaseCount('document_expiration_notices', 0);
});


it('keeps earlier document versions after replacement and resets the review', function () {
    [, $user, , , $enrollment] = profileSetup();
    \Illuminate\Support\Facades\Storage::fake('local');
    $this->post(route('facility.clients.documents.store', $enrollment), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('original.pdf', 20, 'application/pdf'),
        'title' => 'Consent', 'category' => 'consent',
    ])->assertRedirect();
    $document = \App\Models\Document::query()->sole();
    $originalPath = $document->storage_path;
    $this->post(route('facility.clients.documents.review', [$enrollment, $document]), ['status' => 'verified'])->assertRedirect();
    $this->post(route('facility.clients.documents.replace', [$enrollment, $document]), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('revised.pdf', 20, 'application/pdf'),
        'notes' => 'Updated signature',
    ])->assertRedirect(route('facility.clients.edit', $enrollment));
    $document->refresh();
    expect($document->current_version)->toBe(2)
        ->and($document->review_status)->toBe('pending')
        ->and($document->storage_path)->not->toBe($originalPath);
    $versions = $document->versions()->get();
    expect($versions)->toHaveCount(2);
    \Illuminate\Support\Facades\Storage::disk('local')->assertExists($originalPath);
    $old = $versions->firstWhere('version_number', 1);
    $this->get(route('facility.clients.documents.versions.download', [$enrollment, $document, $old]))->assertOk();
    $this->get(route('facility.clients.documents.download', [$enrollment, $document]))->assertOk();
    $this->assertDatabaseCount('document_access_events', 2);
});

it('blocks replacement and historical download outside the authorized client facility', function () {
    [$org, , $facility, , $enrollment] = profileSetup();
    \Illuminate\Support\Facades\Storage::fake('local');
    $this->post(route('facility.clients.documents.store', $enrollment), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('first.pdf', 15, 'application/pdf'),
        'title' => 'Consent', 'category' => 'consent',
    ])->assertRedirect();
    $document = \App\Models\Document::query()->sole();
    $this->post(route('facility.clients.documents.replace', [$enrollment, $document]), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('second.pdf', 15, 'application/pdf'),
    ])->assertRedirect();
    $version = $document->versions()->first();
    $other = Facility::factory()->create(['organization_id' => $org->id]);
    $this->withSession([FacilityContext::SESSION_KEY => $other->id]);
    $this->post(route('facility.clients.documents.replace', [$enrollment, $document]), [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('bad.pdf', 15, 'application/pdf'),
    ])->assertForbidden();
    $this->get(route('facility.clients.documents.versions.download', [$enrollment, $document, $version]))->assertForbidden();
    $this->assertDatabaseCount('document_versions', 2);
    $this->assertDatabaseCount('document_access_events', 0);
});
