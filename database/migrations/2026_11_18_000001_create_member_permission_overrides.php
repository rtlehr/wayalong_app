<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_permission_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope_key', 32);
            $table->string('permission', 100);
            $table->string('decision', 8);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id', 'scope_key', 'permission'], 'member_permission_unique');
            $table->index(['organization_id', 'user_id', 'facility_id'], 'member_permission_scope_index');
        });

        Schema::create('member_permission_override_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('permission', 100);
            $table->string('old_decision', 8)->nullable();
            $table->string('new_decision', 8);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'user_id', 'created_at'], 'member_permission_events_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_permission_override_events');
        Schema::dropIfExists('member_permission_overrides');
    }
};
