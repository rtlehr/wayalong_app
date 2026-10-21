<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('original_filename', 255);
            $table->string('disk', 40);
            $table->string('storage_path', 512)->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('change_notes', 2000)->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'version_number']);
        });
        Schema::table('documents', function (Blueprint $table): void {
            $table->unsignedInteger('current_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('current_version'));
        Schema::dropIfExists('document_versions');
    }
};
