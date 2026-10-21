<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_insurance_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('payer_name', 200);
            $table->string('coverage_order', 20);
            $table->text('member_id')->nullable(); // encrypted via Eloquent
            $table->text('group_number')->nullable(); // encrypted via Eloquent
            $table->string('plan_name', 200)->nullable();
            $table->string('subscriber_name', 200)->nullable();
            $table->string('subscriber_relationship', 30)->nullable();
            $table->date('effective_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('verification_status', 20)->default('unverified');
            $table->date('verified_on')->nullable();
            $table->text('notes')->nullable(); // encrypted via Eloquent
            $table->timestamps();
            $table->unique(['organization_id', 'person_id', 'coverage_order'], 'cip_org_person_order_uq');
            $table->index(['organization_id', 'person_id'], 'cip_org_person_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_insurance_policies');
    }
};
