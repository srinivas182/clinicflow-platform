<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telemedicine wallet (Platform database): prepaid balance per provider,
 * reservations for booked online consults, top-ups and platform settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->integer('balance_cents')->default(0);
            $table->unsignedInteger('reserved_cents')->default(0);
            $table->unsignedInteger('threshold_cents')->nullable();
            $table->boolean('auto_topup')->default(false);
            $table->unsignedInteger('auto_topup_pack_cents')->nullable();
            $table->timestamp('low_balance_notified_at')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->integer('amount_cents');
            $table->integer('balance_after_cents');
            $table->string('reference', 80)->nullable();
            $table->string('description');
            $table->timestamp('created_at');
            $table->index(['wallet_id', 'created_at']);
        });

        Schema::create('wallet_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 80)->unique();
            $table->string('consult_type', 12);
            $table->unsignedInteger('amount_cents');
            $table->string('status', 12)->default('held');
            $table->unsignedInteger('captured_cents')->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_topups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('bonus_cents')->default(0);
            $table->unsignedInteger('vat_cents');
            $table->string('status', 12)->default('pending');
            $table->string('method', 16);
            $table->string('checkout_token', 64)->unique();
            $table->string('gateway', 16)->nullable();
            $table->string('gateway_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['wallet_topups', 'wallet_reservations', 'wallet_transactions', 'wallets', 'platform_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
