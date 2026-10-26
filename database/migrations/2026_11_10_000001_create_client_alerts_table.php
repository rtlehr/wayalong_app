<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_enrollment_id')->constrained('client_enrollments')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('category', 20); // medical, behavioral, general
            $table->string('priority', 20); // high, warning, follow_up
            $table->string('title', 160);
            $table->text('details')->nullable(); // encrypted via Eloquent cast
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'client_enrollment_id', 'resolved_at'], 'ca_org_enrollment_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_alerts');
    }
};
