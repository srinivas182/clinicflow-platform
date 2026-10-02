<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription packages built by the super admin (package builder).
 * Prices are stored in cents, excluding VAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('provider_type', 32)->index();
            $table->string('summary')->nullable();
            $table->unsignedInteger('price_monthly_cents');
            $table->unsignedInteger('price_annual_cents');
            $table->unsignedSmallInteger('trial_days')->default(30);
            $table->json('limits');
            $table->json('features');
            $table->json('addons');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('package_id')->constrained();
            $table->string('status', 16);
            $table->string('billing_period', 8)->default('monthly');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('verification_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('type', 32);
            $table->string('reference')->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_checks');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('packages');
    }
};
