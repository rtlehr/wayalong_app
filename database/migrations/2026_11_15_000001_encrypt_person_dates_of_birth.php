<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The existing DATE column cannot hold authenticated ciphertext.
        // Do not reverse this column change without a controlled restoration procedure.
        Schema::table('people', function (Blueprint $table): void {
            $table->text('date_of_birth')->nullable()->change();
        });

        // Raw query builder avoids model casts when reading legacy plaintext values.
        // Each row is written separately, making a partially completed run retry-safe.
        DB::table('people')->whereNotNull('date_of_birth')->orderBy('id')
            ->chunkById(100, function ($people): void {
                foreach ($people as $person) {
                    $stored = (string) $person->date_of_birth;
                    try {
                        Crypt::decryptString($stored);
                        continue;
                    } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                        // A legacy YYYY-MM-DD value, not a Laravel encrypted payload.
                    }

                    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $stored)) {
                        throw new \RuntimeException('Unexpected birth date format for person ID '.$person->id.'; migration stopped without overwriting it.');
                    }
                    DB::table('people')->where('id', $person->id)->update([
                        'date_of_birth' => Crypt::encryptString($stored),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Intentional: never silently decrypt protected patient data on rollback.
        // Restore an encrypted backup and coordinate application rollback instead.
    }
};
