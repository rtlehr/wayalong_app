<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_billing_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reverses_charge_id')->nullable()->constrained('client_billing_charges')->restrictOnDelete();
            $table->date('service_on');
            $table->string('description', 240);
            $table->bigInteger('amount_cents'); // signed amounts; reversals are negative
            $table->string('responsibility', 20); // insurance or client (planning label only)
            $table->text('notes')->nullable(); // encrypted
            $table->timestamps();
            $table->unique('reverses_charge_id', 'cbc_single_reversal_uq');
            $table->index(['organization_id', 'person_id', 'service_on'], 'cbc_org_person_service_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_billing_charges');
    }
};
