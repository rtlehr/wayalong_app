<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_billing_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('charge_id')->constrained('client_billing_charges')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reverses_payment_id')->nullable()->constrained('client_billing_payments')->restrictOnDelete();
            $table->date('received_on');
            $table->string('payer_type', 20); // insurance or client
            $table->string('method', 30); // cash, check, card, bank_transfer, insurer_remittance, other
            $table->bigInteger('amount_cents'); // positive receipt, negative reversal
            $table->text('reference')->nullable(); // encrypted
            $table->text('notes')->nullable(); // encrypted
            $table->timestamps();
            $table->unique('reverses_payment_id', 'cbp_one_reversal_uq');
            $table->index(['organization_id', 'person_id', 'charge_id'], 'cbp_org_person_charge_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_billing_payments');
    }
};
