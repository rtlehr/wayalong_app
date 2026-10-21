<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('record_type', 80);
            $table->unsignedBigInteger('record_id');
            $table->timestamp('created_at');
            $table->index(['document_id', 'created_at']);
        });

        Schema::create('document_expiration_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('window', 20);
            $table->timestamp('created_at');
            $table->unique(['document_id', 'user_id', 'window'], 'document_expiry_notice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_expiration_notices');
        Schema::dropIfExists('document_access_events');
    }
};
