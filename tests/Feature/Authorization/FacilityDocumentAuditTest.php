<?php

use App\Models\ClientEnrollment;
use App\Models\Document;
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
beforeEach(function (): void { $this->withoutVite(); });

function documentAuditSetup(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user, ['role' => 'staff', 'status' => 'active']);
    test()->actingAs($user)->withSession([OrganizationContext::SESSION_KEY => $organization->id]);
    $facility = Facility::factory()->create(['organization_id' => $organization->id]);
    DB::table('organization_staff_facilities')->insert([
        'organization_id' => $organization->id, 'facility_id' => $facility->id, 'user_id' => $user->id,
        'role' => 'clinician', 'created_at' => now(), 'updated_at' => now(),
    ]);
    test()->withSession([FacilityContext::SESSION_KEY => $facility->id]);
    $person = Person::create(['first_name' => 'Audit', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $facility->id]);
    $enrollment = ClientEnrollment::create(['person_id' => $person->id, 'facility_id' => $facility->id, 'enrolled_at' => today()->toDateString()]);
    return [$organization, $user, $facility, $enrollment];
}

function auditDocument(Organization $organization, User $user, Facility $facility, ClientEnrollment $enrollment, string $title): Document
{
    $document = Document::create([
        'organization_id' => $organization->id, 'facility_id' => $facility->id,
        'uploaded_by_user_id' => $user->id, 'title' => $title, 'category' => 'consent',
        'original_filename' => 'example.pdf', 'disk' => 'local', 'storage_path' => 'wayalong-documents/test/'.uniqid().'.pdf',
        'mime_type' => 'application/pdf', 'file_size' => 512,
    ]);
    $document->attachments()->create(['record_type' => 'client_enrollment', 'record_id' => $enrollment->id]);
    return $document;
}

it('shows authorized client documents and successful download audit events', function (): void {
    [$org, $user, $facility, $enrollment] = documentAuditSetup();
    $document = auditDocument($org, $user, $facility, $enrollment, 'Approved test document');
    DB::table('document_access_events')->insert([
        'document_id' => $document->id, 'user_id' => $user->id, 'action' => 'download',
        'record_type' => 'client_enrollment', 'record_id' => $enrollment->id, 'created_at' => now(),
    ]);
    $this->get(route('facility.documents.audit'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('facilities/DocumentAudit')
        ->where('documents.total', 1)->where('documents.data.0.title', 'Approved test document')
        ->where('events.total', 1)->where('events.data.0.actor', $user->name)->etc());
});

it('does not list documents attached to a different facility or to an unrelated record', function (): void {
    [$org, $user, $facility, $enrollment] = documentAuditSetup();
    auditDocument($org, $user, $facility, $enrollment, 'Visible document');
    $other = Facility::factory()->create(['organization_id' => $org->id]);
    $otherPerson = Person::create(['first_name' => 'Other', 'last_name' => 'Client', 'person_type' => 'client', 'facility_id' => $other->id]);
    $otherEnrollment = ClientEnrollment::create(['person_id' => $otherPerson->id, 'facility_id' => $other->id, 'enrolled_at' => today()->toDateString()]);
    auditDocument($org, $user, $other, $otherEnrollment, 'Secret other facility document');
    $this->get(route('facility.documents.audit'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('documents.total', 1)->where('documents.data.0.title', 'Visible document')->etc());
});

it('denies document audit access without active facility management permissions', function (): void {
    [$org, $user, $facility] = documentAuditSetup();
    DB::table('organization_staff_facilities')->where('organization_id', $org->id)
        ->where('facility_id', $facility->id)->where('user_id', $user->id)->update(['role' => 'staff']);
    $this->get(route('facility.documents.audit'))->assertForbidden();
    DB::table('organization_staff_facilities')->where('organization_id', $org->id)
        ->where('facility_id', $facility->id)->where('user_id', $user->id)->update(['role' => 'clinician']);
    $this->withSession([FacilityContext::SESSION_KEY => null])->get(route('facility.documents.audit'))->assertForbidden();
});
