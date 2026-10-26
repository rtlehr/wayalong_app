<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Laravel encrypted casts produce ciphertext longer than the plaintext.
        Schema::table('insurance_verifications', function (Blueprint $table): void {
            $table->text('reference_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Do not shrink encrypted values: existing ciphertext may exceed 200 bytes.
    }
};
