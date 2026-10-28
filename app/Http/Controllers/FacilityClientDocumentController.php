<?php

namespace App\Http\Controllers;

use App\Models\ClientEnrollment;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Support\DocumentManager;
use App\Support\EncryptedDocumentStorage;
use App\Support\FacilityContext;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FacilityClientDocumentController extends Controller
{
    private function authorizeClient(Request $request, ClientEnrollment $enrollment, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions, string $permission): void
    {
        $facility = $facilities->current($request, $organizations);
        abort_unless($facility !== null &&
            (int) $enrollment->organization_id === (int) $facility->organization_id &&
            (int) $enrollment->facility_id === (int) $facility->id &&
            $permissions->allows($request->user(), $facility, $permission), 403);
        if ($permission === FacilityPermissions::VIEW_CLIENTS
            && ! $permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_CLIENTS)) {
            abort_unless(\Illuminate\Support\Facades\DB::table('client_staff_assignments')
                ->where('organization_id', $facility->organization_id)
                ->where('client_enrollment_id', $enrollment->id)
                ->where('user_id', $request->user()->id)->exists(), 403);
        }
    }

    public function store(Request $request, ClientEnrollment $enrollment, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions, DocumentManager $documents): RedirectResponse
    {
        $this->authorizeClient($request, $enrollment, $organizations, $facilities, $permissions, FacilityPermissions::MANAGE_CLIENTS);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(['custody', 'consent', 'treatment', 'insurance', 'identification', 'other'])],
            'expires_on' => ['nullable', 'date'],
        ]);
        $documents->upload($data['file'], $request->user(), (int) $enrollment->organization_id,
            (int) $enrollment->facility_id, DocumentManager::CLIENT_ENROLLMENT,
            (int) $enrollment->id, $data['title'], $data['category'], $data['expires_on'] ?? null);

        return redirect()->route('facility.clients.edit', $enrollment)->with('success', 'Document uploaded successfully.');
    }

    public function download(Request $request, ClientEnrollment $enrollment, Document $document, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): StreamedResponse
    {
        $this->authorizeClient($request, $enrollment, $organizations, $facilities, $permissions, FacilityPermissions::VIEW_CLIENTS);
        abort_unless((int) $document->organization_id === (int) $enrollment->organization_id &&
            (int) $document->facility_id === (int) $enrollment->facility_id &&
            $document->attachments()->where('record_type', DocumentManager::CLIENT_ENROLLMENT)->where('record_id', $enrollment->id)->exists(), 404);
        abort_unless($document->disk === 'local' && Storage::disk('local')->exists($document->storage_path), 404);

        \Illuminate\Support\Facades\DB::table('document_access_events')->insert([
            'document_id' => $document->id,
            'user_id' => $request->user()->id,
            'action' => 'download',
            'record_type' => DocumentManager::CLIENT_ENROLLMENT,
            'record_id' => $enrollment->id,
            'created_at' => now(),
        ]);

        app(\App\Support\SensitiveAccessAudit::class)->record($request, $enrollment, 'document_download');

        return app(EncryptedDocumentStorage::class)->download($document->storage_path, $document->original_filename);
    }
    public function replace(Request $request, ClientEnrollment $enrollment, Document $document, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions, DocumentManager $documents): RedirectResponse
    {
        $this->authorizeClient($request, $enrollment, $organizations, $facilities, $permissions, FacilityPermissions::MANAGE_CLIENTS);
        $this->authorizeDocumentAttachment($enrollment, $document);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $documents->replace($document, $data['file'], $request->user(), $data['notes'] ?? '');

        return redirect()->route('facility.clients.edit', $enrollment)
            ->with('success', 'Document replacement saved. Review status reset to pending.');
    }

    public function downloadVersion(Request $request, ClientEnrollment $enrollment, Document $document, DocumentVersion $version, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): StreamedResponse
    {
        $this->authorizeClient($request, $enrollment, $organizations, $facilities, $permissions, FacilityPermissions::VIEW_CLIENTS);
        $this->authorizeDocumentAttachment($enrollment, $document);
        abort_unless((int) $version->document_id === (int) $document->id, 404);
        abort_unless($version->disk === 'local' && Storage::disk('local')->exists($version->storage_path), 404);

        \Illuminate\Support\Facades\DB::table('document_access_events')->insert([
            'document_id' => $document->id,
            'user_id' => $request->user()->id,
            'action' => 'download',
            'record_type' => DocumentManager::CLIENT_ENROLLMENT,
            'record_id' => $enrollment->id,
            'created_at' => now(),
        ]);

        app(\App\Support\SensitiveAccessAudit::class)->record($request, $enrollment, 'document_download');

        return app(EncryptedDocumentStorage::class)->download($version->storage_path, $version->original_filename);
    }

    private function authorizeDocumentAttachment(ClientEnrollment $enrollment, Document $document): void
    {
        abort_unless((int) $document->organization_id === (int) $enrollment->organization_id &&
            (int) $document->facility_id === (int) $enrollment->facility_id &&
            $document->attachments()->where('record_type', DocumentManager::CLIENT_ENROLLMENT)
                ->where('record_id', $enrollment->id)->exists(), 404);
    }

    public function review(Request $request, ClientEnrollment $enrollment, Document $document, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): RedirectResponse
    {
        $this->authorizeClient($request, $enrollment, $organizations, $facilities, $permissions, FacilityPermissions::MANAGE_CLIENTS);
        abort_unless((int) $document->organization_id === (int) $enrollment->organization_id &&
            (int) $document->facility_id === (int) $enrollment->facility_id &&
            $document->attachments()->where('record_type', DocumentManager::CLIENT_ENROLLMENT)
                ->where('record_id', $enrollment->id)->exists(), 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['verified', 'rejected', 'pending'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($document, $data, $request): void {
            $document->update([
                'review_status' => $data['status'], 'review_notes' => $data['notes'] ?? null,
                'reviewed_by_user_id' => $request->user()->id, 'reviewed_at' => now(),
            ]);
            \Illuminate\Support\Facades\DB::table('document_review_events')->insert([
                'document_id' => $document->id, 'reviewed_by_user_id' => $request->user()->id,
                'status' => $data['status'], 'notes' => $data['notes'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        return redirect()->route('facility.clients.edit', $enrollment)
            ->with('success', 'Document review recorded. No access permissions were changed.');
    }

}
