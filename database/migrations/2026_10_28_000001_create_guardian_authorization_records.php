<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_authorization_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('guardian_person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('record_type', 20);
            $table->string('subject', 40);
            $table->string('decision', 30);
            $table->date('effective_on');
            $table->date('expires_on')->nullable();
            $table->string('evidence_reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['client_enrollment_id', 'guardian_person_id', 'record_type'], 'guardian_authorization_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_authorization_records');
    }
};
