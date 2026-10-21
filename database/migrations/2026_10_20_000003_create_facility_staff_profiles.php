<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_staff_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('job_title')->nullable();
            $table->string('staff_type')->nullable();
            $table->string('credentials')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'facility_id', 'user_id'], 'facility_staff_profile_unique');
        });
        // Preserve prior organization-level staff details for each existing facility assignment.
        $details = DB::table('organization_staff_details')->get();
        foreach ($details as $detail) {
            $facilities = DB::table('organization_staff_facilities')
                ->where('organization_id', $detail->organization_id)->where('user_id', $detail->user_id)->get();
            foreach ($facilities as $facility) {
                DB::table('facility_staff_profiles')->insert([
                    'organization_id' => $detail->organization_id, 'facility_id' => $facility->facility_id,
                    'user_id' => $detail->user_id, 'job_title' => $detail->job_title,
                    'staff_type' => $detail->staff_type, 'credentials' => $detail->credentials,
                    'notes' => $detail->notes, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_staff_profiles');
    }
};
