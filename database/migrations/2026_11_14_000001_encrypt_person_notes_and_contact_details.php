<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONTACT_FIELDS = ['value', 'line_1', 'line_2', 'city', 'state', 'postal_code'];

    public function up(): void
    {
        // Encrypted payloads exceed the original varchar capacities. Never truncate ciphertext.
        Schema::table('contact_methods', function (Blueprint $table): void {
            foreach (self::CONTACT_FIELDS as $column) {
                $table->text($column)->nullable()->change();
            }
        });

        // Work through the raw query builder: encrypted Eloquent casts cannot read legacy plaintext.
        // Each row is committed independently so this remains safe to re-run after an interruption.
        DB::table('contact_methods')->orderBy('id')->chunkById(100, function ($contacts): void {
            foreach ($contacts as $contact) {
                $changes = [];
                foreach (self::CONTACT_FIELDS as $column) {
                    if ($contact->{$column} !== null) {
                        $changes[$column] = $this->encryptUnlessEncrypted((string) $contact->{$column});
                    }
                }
                if ($changes !== []) {
                    DB::table('contact_methods')->where('id', $contact->id)->update($changes);
                }
            }
        });

        DB::table('people')->whereNotNull('notes')->orderBy('id')->chunkById(100, function ($people): void {
            foreach ($people as $person) {
                DB::table('people')->where('id', $person->id)->update([
                    'notes' => $this->encryptUnlessEncrypted((string) $person->notes),
                ]);
            }
        });
    }

    private function encryptUnlessEncrypted(string $value): string
    {
        try {
            Crypt::decryptString($value);
            return $value;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return Crypt::encryptString($value);
        }
    }

    public function down(): void
    {
        // Deliberately do not decrypt sensitive data or shrink ciphertext columns on rollback.
        // Restore from a controlled, encrypted backup if this deployment must be reverted.
    }
};
