<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-house lab, revenue attribution, credit notes, ledger, cash-ups and the message log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('visit_id')->constrained();
            $table->foreignUlid('patient_id')->constrained();
            $table->unsignedBigInteger('ordering_staff_id');
            $table->string('status', 16)->default('ordered');
            $table->string('sample_barcode', 32)->nullable()->unique();
            $table->unsignedBigInteger('collected_by')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->unsignedBigInteger('resulted_by')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('has_critical')->default(false);
            $table->timestamp('critical_acknowledged_at')->nullable();
            $table->unsignedBigInteger('critical_acknowledged_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('doctor_comment')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'ordering_staff_id']);
        });

        Schema::create('lab_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('lab_order_id')->constrained()->cascadeOnDelete();
            $table->string('test_code', 16);
            $table->string('name');
            $table->string('unit', 24);
            $table->string('reference', 40)->nullable();
            $table->decimal('value', 10, 2)->nullable();
            $table->string('flag', 16)->nullable();
        });

        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('attributed_staff_id')->nullable()->after('total_cents');
        });

        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 24)->unique();
            $table->foreignUlid('invoice_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->string('reason');
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedInteger('credited_cents')->default(0)->after('total_cents');
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->string('account', 32)->index();
            $table->unsignedInteger('debit_cents')->default(0);
            $table->unsignedInteger('credit_cents')->default(0);
            $table->string('source_type', 32);
            $table->string('source_id', 40);
            $table->string('description');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('cash_up_id')->nullable()->after('received_by');
        });

        Schema::create('cash_ups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->date('day');
            $table->json('expected');
            $table->unsignedInteger('expected_cash_cents');
            $table->unsignedInteger('counted_cash_cents');
            $table->integer('difference_cents');
            $table->string('reason')->nullable();
            $table->timestamp('closed_at');
            $table->unique(['staff_id', 'day']);
        });

        Schema::create('message_log', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 8);
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('status', 12);
            $table->unsignedSmallInteger('units')->default(1);
            $table->string('related_type', 32)->nullable();
            $table->string('related_id', 40)->nullable();
            $table->timestamp('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_log');
        Schema::dropIfExists('cash_ups');
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('cash_up_id'));
        Schema::dropIfExists('ledger_entries');
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('credited_cents'));
        Schema::dropIfExists('credit_notes');
        Schema::table('invoice_lines', fn (Blueprint $t) => $t->dropColumn('attributed_staff_id'));
        Schema::dropIfExists('lab_results');
        Schema::dropIfExists('lab_orders');
    }
};
