<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('organization_staff_facilities', function (Blueprint $table): void {
            $table->string('role')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organization_staff_facilities', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
