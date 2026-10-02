<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visits (queue), tickets, invoices, payments and refunds for one provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_counters', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('visits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('patient_id')->constrained();
            $table->foreignUlid('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('visit_date')->index();
            $table->string('ticket', 8);
            $table->string('stage', 16)->index();
            $table->string('payer_type', 16);
            $table->unsignedBigInteger('preferred_staff_id')->nullable();
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->string('triage_colour', 8)->nullable();
            $table->string('left_reason', 32)->nullable();
            $table->string('left_note')->nullable();
            $table->string('check_in_channel', 16)->default('reception');
            $table->timestamp('stage_changed_at');
            $table->timestamps();

            $table->unique(['visit_date', 'ticket']);
        });

        Schema::create('visit_stage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('visit_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage', 16)->nullable();
            $table->string('to_stage', 16);
            $table->unsignedBigInteger('by_staff_id')->nullable();
            $table->timestamp('occurred_at');
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('number', 24)->unique();
            $table->foreignUlid('patient_id')->constrained();
            $table->foreignUlid('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payer_type', 16);
            $table->string('status', 16)->default('open');
            $table->unsignedInteger('total_cents')->default(0);
            $table->unsignedInteger('paid_cents')->default(0);
            $table->boolean('needs_review')->default(false);
            $table->string('review_note')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('code', 24)->nullable();
            $table->string('description');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('unit_cents');
            $table->unsignedInteger('total_cents');
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('invoice_id')->constrained();
            $table->string('method', 16);
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->string('status', 16);
            $table->string('reference')->nullable();
            $table->string('gateway', 32)->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->string('reason');
            $table->string('status', 16);
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['refunds', 'payments', 'invoice_lines', 'invoices', 'visit_stage_events', 'visits', 'ticket_counters'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
