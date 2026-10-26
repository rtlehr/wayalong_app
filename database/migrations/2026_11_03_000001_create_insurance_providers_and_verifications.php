<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('insurance_providers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('payer_code', 80)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'name'], 'ip_org_name_uq');
        });
        Schema::table('client_insurance_policies', function (Blueprint $table): void {
            $table->foreignId('insurance_provider_id')->nullable()->after('payer_name')->constrained('insurance_providers')->restrictOnDelete();
        });
        Schema::create('insurance_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_insurance_policy_id')->constrained('client_insurance_policies')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->string('method', 30);
            $table->date('checked_on');
            $table->string('reference_number', 200)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'client_insurance_policy_id'], 'iv_org_policy_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_verifications');
        Schema::table('client_insurance_policies', fn (Blueprint $table) => $table->dropConstrainedForeignId('insurance_provider_id'));
        Schema::dropIfExists('insurance_providers');
    }
};
