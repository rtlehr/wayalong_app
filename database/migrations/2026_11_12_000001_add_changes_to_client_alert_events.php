<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A previous, interrupted MySQL migration may already have added this
        // column without Laravel recording the migration as completed.
        if (! Schema::hasColumn('client_alert_events', 'changes')) {
            Schema::table('client_alert_events', function (Blueprint $table): void {
                $table->text('changes')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('client_alert_events', 'changes')) {
            Schema::table('client_alert_events', function (Blueprint $table): void {
                $table->dropColumn('changes');
            });
        }
    }
};
