<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MariaDB DDL can commit individual CREATE TABLE statements even when a
        // later statement fails. Preserve tables from a partially run migration.
        if (! Schema::hasTable('facility_assignment_roles')) {
            Schema::create('facility_assignment_roles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('assignment_id')->constrained('organization_staff_facilities')->cascadeOnDelete();
                $table->string('role', 100);
                $table->timestamps();
                $table->unique(['assignment_id', 'role']);
            });
        }

        if (! Schema::hasTable('facility_role_change_events')) {
            Schema::create('facility_role_change_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('old_primary_role', 100)->nullable();
                $table->string('new_primary_role', 100);
                $table->json('old_additional_roles');
                $table->json('new_additional_roles');
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // Explicit name avoids MariaDB's 64-character identifier limit.
        // Added separately to repair a prior run that created the table but
        // failed on this index.
        if (! Schema::hasIndex('facility_role_change_events', 'frce_org_fac_created_idx')) {
            Schema::table('facility_role_change_events', function (Blueprint $table): void {
                $table->index(['organization_id', 'facility_id', 'created_at'], 'frce_org_fac_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_role_change_events');
        Schema::dropIfExists('facility_assignment_roles');
    }
};
