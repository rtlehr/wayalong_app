<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_alert_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_alert_id')->constrained('client_alerts')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20); // created, resolved; additional audited actions in future
            $table->text('reason')->nullable(); // Eloquent encrypted cast
            $table->timestamps();
            $table->index(['organization_id', 'client_alert_id', 'id'], 'cae_org_alert_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_alert_events');
    }
};
