<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform database: lab test catalogue (reference ranges and critical
 * limits) and monthly messaging usage per provider (email + SMS counted as
 * one combined allowance; overage billed on the next subscription invoice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_tests', function (Blueprint $table): void {
            $table->string('code', 16)->primary();
            $table->string('name');
            $table->string('unit', 24);
            $table->decimal('ref_low', 10, 2)->nullable();
            $table->decimal('ref_high', 10, 2)->nullable();
            $table->decimal('critical_low', 10, 2)->nullable();
            $table->decimal('critical_high', 10, 2)->nullable();
            $table->unsignedInteger('price_cents');
        });

        Schema::create('message_usage', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('period', 7);
            $table->unsignedInteger('units')->default(0);
            $table->unique(['tenant_id', 'period']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->unsignedInteger('messaging_units')->default(0)->after('amount_cents');
            $table->unsignedInteger('messaging_overage_cents')->default(0)->after('messaging_units');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropColumn(['messaging_units', 'messaging_overage_cents']);
        });
        Schema::dropIfExists('message_usage');
        Schema::dropIfExists('lab_tests');
    }
};
