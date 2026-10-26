<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Encrypts document bodies before they reach the private storage disk.
 * New paths use .encrypted as a format marker. Older paths are intentionally
 * readable until a separately verified legacy-file migration is performed.
 */
final class EncryptedDocumentStorage
{
    private const DISK = 'local';

    public function store(UploadedFile $file, int $organizationId): string
    {
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw new \RuntimeException('Could not read the uploaded document.');
        }

        $path = 'wayalong-documents/'.$organizationId.'/'.Str::random(40).'.encrypted';
        $ciphertext = Crypt::encryptString(base64_encode($bytes));
        if (! Storage::disk(self::DISK)->put($path, $ciphertext)) {
            throw new \RuntimeException('Could not securely store the uploaded document.');
        }

        return $path;
    }

    public function download(string $path, string $filename): StreamedResponse
    {
        $ciphertextOrLegacy = Storage::disk(self::DISK)->get($path);
        if (str_ends_with($path, '.encrypted')) {
            // An invalid ciphertext must fail closed, never fall back to plaintext.
            $decoded = base64_decode(Crypt::decryptString($ciphertextOrLegacy), true);
            if ($decoded === false) {
                throw new \RuntimeException('Document contents could not be decoded.');
            }
            $bytes = $decoded;
        } else {
            // Compatibility only: legacy documents are NOT encrypted at rest.
            $bytes = $ciphertextOrLegacy;
        }

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, $filename, [
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
