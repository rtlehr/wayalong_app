<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_insurance_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('charge_id')->constrained('client_billing_charges')->restrictOnDelete();
            $table->foreignId('client_insurance_policy_id')->constrained('client_insurance_policies')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('draft');
            $table->string('claim_number', 100)->nullable();
            $table->date('submitted_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'person_id', 'status'], 'cic_org_person_status_idx');
        });

        Schema::create('client_insurance_claim_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('claim_id')->constrained('client_insurance_claims')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 30);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'claim_id'], 'cice_org_claim_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_insurance_claim_events');
        Schema::dropIfExists('client_insurance_claims');
    }
};
