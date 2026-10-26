<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_insurance_claims', function (Blueprint $table): void {
            $table->date('adjudicated_on')->nullable();
            $table->bigInteger('allowed_amount_cents')->nullable();
            $table->bigInteger('insurer_paid_cents')->nullable();
            $table->bigInteger('patient_responsibility_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_insurance_claims', function (Blueprint $table): void {
            $table->dropColumn(['adjudicated_on', 'allowed_amount_cents', 'insurer_paid_cents', 'patient_responsibility_cents']);
        });
    }
};
