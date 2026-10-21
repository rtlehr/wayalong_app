<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_guardian_relationships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_person_id')->constrained('people')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['client_enrollment_id', 'guardian_person_id'], 'client_guardian_unique');
        });
        Schema::create('client_staff_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['client_enrollment_id', 'user_id'], 'client_staff_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_staff_assignments');
        Schema::dropIfExists('client_guardian_relationships');
    }
};
