<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid packages: a fixed set of services paid for in advance (not cover or
 * insurance). Valid for three years from purchase (Consumer Protection Act s63).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prepaid_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('price_cents');
            $table->json('items');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('patient_packages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('patient_id')->constrained();
            $table->foreignId('prepaid_package_id')->constrained();
            $table->foreignUlid('invoice_id')->constrained();
            $table->unsignedInteger('price_cents');
            $table->json('remaining');
            $table->string('status', 10)->default('pending');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('package_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_package_id')->constrained();
            $table->foreignId('invoice_line_id')->unique()->constrained();
            $table->string('service', 40);
            $table->unsignedInteger('amount_cents');
            $table->unsignedBigInteger('by')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_redemptions');
        Schema::dropIfExists('patient_packages');
        Schema::dropIfExists('prepaid_packages');
    }
};
