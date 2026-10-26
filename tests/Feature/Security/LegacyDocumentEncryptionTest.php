<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use App\Support\EncryptedDocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function legacyDocumentFixture(): array
{
    Storage::fake('local');
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $path = 'wayalong-documents/'.$organization->id.'/old-document.pdf';
    Storage::disk('local')->put($path, '%PDF-legacy-sensitive-content');
    $document = Document::create([
        'organization_id' => $organization->id, 'uploaded_by_user_id' => $user->id,
        'title' => 'Legacy document', 'category' => 'other', 'original_filename' => 'original.pdf',
        'disk' => 'local', 'storage_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => 29,
    ]);
    $version = DocumentVersion::create([
        'document_id' => $document->id, 'version_number' => 1, 'uploaded_by_user_id' => $user->id,
        'original_filename' => 'original.pdf', 'disk' => 'local', 'storage_path' => $path,
        'mime_type' => 'application/pdf', 'file_size' => 29,
    ]);
    return [$document, $version, $path];
}

it('defaults to a read-only inventory and requires backup confirmation for execution', function () {
    [$document, $version, $path] = legacyDocumentFixture();
    $this->artisan('wayalong:encrypt-legacy-documents')->assertExitCode(0);
    $this->artisan('wayalong:encrypt-legacy-documents', ['--execute' => true])->assertExitCode(1);
    expect($document->fresh()->storage_path)->toBe($path)
        ->and($version->fresh()->storage_path)->toBe($path)
        ->and(Storage::disk('local')->get($path))->toBe('%PDF-legacy-sensitive-content');
});

it('encrypts legacy bytes, atomically redirects current and historical versions, and is safe to rerun', function () {
    [$document, $version, $path] = legacyDocumentFixture();
    $this->artisan('wayalong:encrypt-legacy-documents', ['--execute' => true, '--backup-confirmed' => true])->assertExitCode(0);
    $newPath = $document->fresh()->storage_path;
    expect($newPath)->toEndWith('.encrypted')
        ->and($newPath)->not->toBe($path)
        ->and($version->fresh()->storage_path)->toBe($newPath)
        ->and(Storage::disk('local')->get($newPath))->not->toContain('%PDF-legacy-sensitive-content')
        ->and(Storage::disk('local')->get($path))->toBe('%PDF-legacy-sensitive-content');
    $response = app(EncryptedDocumentStorage::class)->download($newPath, 'original.pdf');
    ob_start();
    $response->sendContent();
    $contents = ob_get_clean();
    expect($contents)->toBe('%PDF-legacy-sensitive-content');
    $this->artisan('wayalong:encrypt-legacy-documents', ['--execute' => true, '--backup-confirmed' => true])->assertExitCode(0);
    expect($document->fresh()->storage_path)->toBe($newPath);
});

it('refuses missing files without rewriting metadata', function () {
    [$document, , $path] = legacyDocumentFixture();
    Storage::disk('local')->delete($path);
    $this->artisan('wayalong:encrypt-legacy-documents', ['--execute' => true, '--backup-confirmed' => true])->assertExitCode(1);
    expect(DB::table('documents')->where('id', $document->id)->value('storage_path'))->toBe($path);
});
