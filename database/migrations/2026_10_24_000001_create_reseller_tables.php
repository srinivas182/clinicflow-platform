<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reseller programme: resellers, the providers they referred, and commission per paid subscription invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resellers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 20)->nullable();
            $table->string('code', 20)->unique();
            $table->decimal('commission_percent', 5, 2)->default(20);
            $table->unsignedSmallInteger('commission_months')->default(12);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('reseller_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reseller_id')->constrained();
            $table->string('tenant_id')->unique();
            $table->timestamp('referred_at');
        });

        Schema::create('reseller_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reseller_id')->constrained();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('subscription_invoice_id')->unique();
            $table->unsignedInteger('base_cents');
            $table->unsignedInteger('amount_cents');
            $table->string('period', 7);
            $table->string('status', 8)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->string('payout_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_commissions');
        Schema::dropIfExists('reseller_referrals');
        Schema::dropIfExists('resellers');
    }
};
