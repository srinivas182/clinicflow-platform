<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corporate wellness billing (one invoice per event to the employer) and
 * anonymised employer report links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('corporate_account_id')->constrained();
            $table->foreignId('wellness_event_id')->unique()->constrained();
            $table->unsignedInteger('screened');
            $table->unsignedInteger('rate_cents');
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('vat_cents');
            $table->unsignedInteger('total_cents');
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('wellness_report_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wellness_event_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wellness_report_links');
        Schema::dropIfExists('corporate_invoices');
    }
};
