<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });

        // Polymorphic records can later be shared by clients, guardians and other contacts.
        Schema::create('contact_methods', function (Blueprint $table): void {
            $table->id();
            $table->morphs('contactable');
            $table->string('kind', 20); // email, phone, address
            $table->string('label', 50)->default('Other');
            $table->boolean('is_primary')->default(false);
            $table->string('value')->nullable();
            $table->string('line_1')->nullable();
            $table->string('line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('country', 2)->default('US');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['contactable_type', 'contactable_id', 'kind']);
        });

        // Preserve existing contact data in the new reusable records.
        DB::table('users')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                foreach ([['phone', 'Home'], ['mobile_phone', 'Mobile']] as [$field, $label]) {
                    if ($user->{$field}) {
                        DB::table('contact_methods')->insert(['contactable_type' => 'App\Models\User', 'contactable_id' => $user->id,
                            'kind' => 'phone', 'label' => $label, 'is_primary' => $field === 'phone',
                            'value' => $user->{$field}, 'country' => 'US', 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                if ($user->address_line_1) {
                    DB::table('contact_methods')->insert(['contactable_type' => 'App\Models\User', 'contactable_id' => $user->id,
                        'kind' => 'address', 'label' => 'Home', 'is_primary' => true,
                        'line_1' => $user->address_line_1, 'line_2' => $user->address_line_2,
                        'city' => $user->city, 'state' => $user->state, 'postal_code' => $user->postal_code,
                        'country' => 'US', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_methods');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_active'));
    }
};
