<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 255);
            $table->string('category', 60);
            $table->string('original_filename', 255);
            $table->string('disk', 40);
            $table->string('storage_path', 512)->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->date('expires_on')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'facility_id']);
        });
        Schema::create('document_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('record_type', 80);
            $table->unsignedBigInteger('record_id');
            $table->timestamps();
            $table->unique(['document_id', 'record_type', 'record_id'], 'document_attachment_unique');
            $table->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_attachments');
        Schema::dropIfExists('documents');
    }
};
