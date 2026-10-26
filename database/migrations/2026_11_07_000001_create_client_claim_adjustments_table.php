<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_claim_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('claim_id')->constrained('client_insurance_claims')->restrictOnDelete();
            $table->foreignId('charge_id')->constrained('client_billing_charges')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reverses_adjustment_id')->nullable()->constrained('client_claim_adjustments')->restrictOnDelete();
            $table->date('adjusted_on');
            $table->string('kind', 30);
            $table->bigInteger('amount_cents');
            $table->text('reason');
            $table->timestamps();
            $table->index(['organization_id', 'charge_id'], 'cca_org_charge_idx');
            $table->unique('reverses_adjustment_id', 'cca_unique_reversal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_claim_adjustments');
    }
};
