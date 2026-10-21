<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_guardian_relationships', function (Blueprint $table): void {
            $table->string('relationship_label', 40)->default('Guardian');
            $table->boolean('is_emergency_contact')->default(false);
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_guardian_relationships', function (Blueprint $table): void {
            $table->dropColumn(['relationship_label', 'is_emergency_contact', 'notes']);
        });
    }
};
