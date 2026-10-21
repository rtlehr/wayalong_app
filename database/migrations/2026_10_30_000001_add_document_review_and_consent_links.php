<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('review_status', 20)->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
        });
        Schema::table('guardian_authorization_records', function (Blueprint $table): void {
            $table->foreignId('evidence_document_id')->nullable()->constrained('documents')->restrictOnDelete();
        });
        Schema::create('document_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('document_review_events');
        Schema::table('guardian_authorization_records', fn (Blueprint $table) => $table->dropConstrainedForeignId('evidence_document_id'));
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['review_status', 'reviewed_at', 'review_notes']);
        });
    }
};
