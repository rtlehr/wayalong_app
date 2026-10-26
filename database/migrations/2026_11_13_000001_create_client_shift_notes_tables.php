<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_shift_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_enrollment_id')->constrained('client_enrollments')->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->date('shift_date');
            $table->string('shift', 20);
            $table->text('summary');
            $table->text('observations')->nullable();
            $table->text('significant_events')->nullable();
            $table->text('interventions')->nullable();
            $table->text('handoff')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'client_enrollment_id', 'created_at'], 'csn_org_enrollment_recent');
        });
        Schema::create('client_shift_note_acknowledgments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_shift_note_id')->constrained('client_shift_notes')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('acknowledged_at');
            $table->timestamps();
            $table->unique(['client_shift_note_id', 'user_id'], 'csna_note_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_shift_note_acknowledgments');
        Schema::dropIfExists('client_shift_notes');
    }
};
