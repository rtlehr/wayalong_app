<?php

namespace App\Support;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Storage and attachment operations shared by future authorized modules.
 * The calling controller must first authorize the target record; this service
 * also enforces organization and facility boundaries for attachment reuse.
 * Never accept record_type or organization_id directly from browser input.
 */
final class DocumentManager
{
    public const CLIENT_ENROLLMENT = 'client_enrollment';

    /** @return Collection<int, Document> */
    public function forRecord(string $recordType, int $recordId, int $organizationId, ?int $facilityId): Collection
    {
        return Document::query()
            ->where('organization_id', $organizationId)
            ->where('facility_id', $facilityId)
            ->whereHas('attachments', fn ($query) => $query
                ->where('record_type', $recordType)->where('record_id', $recordId))
            ->orderByDesc('id')->get();
    }

    public function upload(UploadedFile $file, User $uploader, int $organizationId, ?int $facilityId, string $recordType, int $recordId, string $title, string $category, ?string $expiresOn): Document
    {
        // All uploads remain on a non-public disk. A random server-generated name
        // prevents user filenames from becoming paths or URLs.
        $disk = 'local';
        $path = app(EncryptedDocumentStorage::class)->store($file, $organizationId);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored.']);
        }
        try {
            return DB::transaction(function () use ($file, $uploader, $organizationId, $facilityId, $recordType, $recordId, $title, $category, $expiresOn, $disk, $path): Document {
                $document = Document::create([
                    'organization_id' => $organizationId,
                    'facility_id' => $facilityId,
                    'uploaded_by_user_id' => $uploader->id,
                    'title' => $title,
                    'category' => $category,
                    'original_filename' => $file->getClientOriginalName(),
                    'disk' => $disk,
                    'storage_path' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'file_size' => $file->getSize(),
                    'expires_on' => $expiresOn,
                ]);
                $document->attachments()->create(['record_type' => $recordType, 'record_id' => $recordId]);
                return $document;
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    /**
     * Replace current bytes without changing the document identity or attachments.
     * The caller must authorize management of the owning record before invoking this.
     */
    public function replace(Document $document, UploadedFile $file, User $uploader, string $notes = ''): Document
    {
        $disk = 'local';
        $path = app(EncryptedDocumentStorage::class)->store($file, (int) $document->organization_id);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The replacement could not be stored.']);
        }

        try {
            DB::transaction(function () use ($document, $file, $uploader, $notes, $disk, $path): void {
                $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                // Existing records predate the versioning feature: capture their original
                // bytes and metadata when the first replacement occurs.
                DocumentVersion::query()->firstOrCreate(
                    ['document_id' => $locked->id, 'version_number' => (int) $locked->current_version],
                    [
                        'uploaded_by_user_id' => $locked->uploaded_by_user_id,
                        'original_filename' => $locked->original_filename,
                        'disk' => $locked->disk,
                        'storage_path' => $locked->storage_path,
                        'mime_type' => $locked->mime_type,
                        'file_size' => $locked->file_size,
                    ]
                );
                $next = (int) $locked->current_version + 1;
                DocumentVersion::create([
                    'document_id' => $locked->id,
                    'version_number' => $next,
                    'uploaded_by_user_id' => $uploader->id,
                    'original_filename' => $file->getClientOriginalName(),
                    'disk' => $disk,
                    'storage_path' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'file_size' => $file->getSize(),
                    'change_notes' => $notes ?: null,
                ]);
                $locked->update([
                    'current_version' => $next,
                    'original_filename' => $file->getClientOriginalName(),
                    'disk' => $disk,
                    'storage_path' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'file_size' => $file->getSize(),
                    // A replacement must be re-reviewed, even if the previous file was verified.
                    'review_status' => 'pending',
                    'review_notes' => null,
                    'reviewed_at' => null,
                    'reviewed_by_user_id' => null,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        return $document->refresh();
    }

    /** The caller must have authorized BOTH records before using this API. */
    public function attach(Document $document, string $recordType, int $recordId, int $organizationId, ?int $facilityId): void
    {
        abort_unless((int) $document->organization_id === $organizationId &&
            ($document->facility_id === null ? $facilityId === null : (int) $document->facility_id === $facilityId), 403);
        DocumentAttachment::firstOrCreate([
            'document_id' => $document->id,
            'record_type' => $recordType,
            'record_id' => $recordId,
        ]);
    }
}
