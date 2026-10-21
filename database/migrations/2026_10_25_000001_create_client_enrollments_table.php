<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->date('enrolled_at');
            $table->date('discharged_at')->nullable();
            $table->timestamps();
            $table->unique(['person_id', 'facility_id']);
            $table->index(['organization_id', 'facility_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_enrollments');
    }
};
