<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_access_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('client_enrollment_id');
            $table->unsignedBigInteger('user_id');
            $table->string('section', 32);
            $table->string('outcome', 16);
            $table->timestamp('created_at');
            $table->index(['organization_id', 'client_enrollment_id', 'created_at'], 'clinical_access_client_idx');
            $table->index(['organization_id', 'user_id', 'created_at'], 'clinical_access_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_access_events');
    }
};
