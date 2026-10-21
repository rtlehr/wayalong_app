<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->foreignId('facility_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('facility_id');
            $table->dropColumn(['declined_at', 'decline_reason']);
        });
    }
};
