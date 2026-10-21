<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Non-destructive first stage of the organization-owned account conversion.
 * Ambiguous multi-organization accounts deliberately remain unassigned.
 * Do not remove organization_user or users.email's unique index in this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained('organizations')->restrictOnDelete();
        });

        // Keep this as one set-based UPDATE to be safe for both MySQL and SQLite.
        // Users with zero or multiple memberships, including platform accounts,
        // remain NULL for explicit review in the final account-splitting phase.
        $singleOwnership = DB::table('organization_user')
            ->select('user_id')
            ->selectRaw('MIN(organization_id) AS organization_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) = 1')
            ->get();

        foreach ($singleOwnership as $membership) {
            DB::table('users')
                ->where('id', $membership->user_id)
                ->where('is_platform_admin', false)
                ->whereNull('organization_id')
                ->update(['organization_id' => $membership->organization_id]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
