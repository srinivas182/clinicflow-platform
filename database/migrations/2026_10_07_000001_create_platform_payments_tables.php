<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform payments: the super admin's own gateway accounts (subscription
 * billing), the gateways offered to providers, and subscription invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('gateway', 16)->unique();
            $table->string('mode', 8)->default('test');
            $table->boolean('enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('offered_to_providers')->default(true);
            $table->text('credentials')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 24)->unique();
            $table->string('tenant_id');
            $table->foreignId('subscription_id')->constrained();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('vat_cents');
            $table->unsignedInteger('total_cents');
            $table->string('status', 16)->default('open');
            $table->string('checkout_token', 64)->unique();
            $table->string('gateway', 16)->nullable();
            $table->string('gateway_reference')->nullable();
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('payment_gateway_configs');
    }
};
