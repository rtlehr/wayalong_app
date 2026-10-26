<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Offline migration of legacy document bodies. Never destroys original bytes:
 * those must be retired separately, only after verification and backups.
 */
final class LegacyDocumentEncryptor
{
    /** @return array{pending:int,converted:int,skipped:int} */
    public function run(bool $execute = false): array
    {
        $paths = DB::table('documents')->where('disk', 'local')->where('storage_path', 'not like', '%.encrypted')
            ->pluck('storage_path')
            ->merge(DB::table('document_versions')->where('disk', 'local')->where('storage_path', 'not like', '%.encrypted')->pluck('storage_path'))
            ->unique()->values();

        // Reject unsupported storage rather than silently declaring migration complete.
        $unsupported = DB::table('documents')->where('disk', '!=', 'local')->count()
            + DB::table('document_versions')->where('disk', '!=', 'local')->count();
        if ($unsupported > 0) {
            throw new RuntimeException('Unsupported document disks exist; review them before conversion.');
        }

        $converted = 0;
        $skipped = 0;
        foreach ($paths as $oldPath) {
            if (! is_string($oldPath) || ! str_starts_with($oldPath, 'wayalong-documents/') || str_contains($oldPath, '..')) {
                throw new RuntimeException('Unsafe legacy document path detected; no further files converted.');
            }
            $documents = DB::table('documents')->where('storage_path', $oldPath)->get(['id', 'organization_id']);
            $versions = DB::table('document_versions as v')->join('documents as d', 'd.id', '=', 'v.document_id')
                ->where('v.storage_path', $oldPath)->get(['v.id', 'd.organization_id']);
            $organizations = $documents->pluck('organization_id')->merge($versions->pluck('organization_id'))->unique()->values();
            if ($organizations->count() !== 1) {
                throw new RuntimeException('Document path is shared between organizations or has no owner.');
            }
            $orgId = (int) $organizations->first();
            if (! str_starts_with($oldPath, 'wayalong-documents/'.$orgId.'/')) {
                throw new RuntimeException('Document path does not match its organization.');
            }
            if (! Storage::disk('local')->exists($oldPath)) {
                throw new RuntimeException('Legacy document file is missing; migration stopped.');
            }
            if (! $execute) {
                continue;
            }

            $bytes = Storage::disk('local')->get($oldPath);
            $encrypted = Crypt::encryptString(base64_encode($bytes));
            $newPath = 'wayalong-documents/'.$orgId.'/'.Str::random(40).'.encrypted';
            if (! Storage::disk('local')->put($newPath, $encrypted)) {
                throw new RuntimeException('Could not write encrypted document.');
            }
            try {
                $recovered = base64_decode(Crypt::decryptString(Storage::disk('local')->get($newPath)), true);
                if ($recovered === false || ! hash_equals(hash('sha256', $bytes), hash('sha256', $recovered))) {
                    throw new RuntimeException('Encrypted document failed integrity verification.');
                }
                DB::transaction(function () use ($oldPath, $newPath): void {
                    // Both current and historical references to the same bytes must move together.
                    DB::table('documents')->where('disk', 'local')->where('storage_path', $oldPath)->update(['storage_path' => $newPath]);
                    DB::table('document_versions')->where('disk', 'local')->where('storage_path', $oldPath)->update(['storage_path' => $newPath]);
                });
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete($newPath);
                throw $exception;
            }
            $converted++;
        }

        return ['pending' => $paths->count(), 'converted' => $converted, 'skipped' => $skipped];
    }
}
