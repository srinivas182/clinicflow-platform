<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-house pharmacy (stock, batches, dispensing, owing list, S5/S6 register),
 * collection, quotes for procedures, and claim remittances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('medicine_id')->unique();
            $table->string('nappi_code', 12);
            $table->string('description');
            $table->string('schedule', 4);
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('reorder_level')->default(0);
            $table->timestamps();
        });

        Schema::create('stock_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 40);
            $table->date('expiry_date');
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->index(['stock_item_id', 'expiry_date']);
        });

        Schema::create('dispensings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('prescription_id')->constrained();
            $table->unsignedBigInteger('prescription_item_id');
            $table->foreignUlid('visit_id')->constrained();
            $table->foreignId('stock_batch_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('dispensed_by');
            $table->timestamp('dispensed_at');
            $table->timestamp('returned_at')->nullable();
        });

        Schema::create('owing_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('prescription_id')->constrained();
            $table->unsignedBigInteger('prescription_item_id');
            $table->foreignUlid('patient_id')->constrained();
            $table->foreignUlid('visit_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->string('status', 12)->default('owing');
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        // Append-only S5/S6 register (Medicines Act). One row per movement.
        Schema::create('scheduled_register', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_item_id')->constrained();
            $table->string('schedule', 4);
            $table->string('movement', 12);
            $table->integer('quantity');
            $table->integer('balance_after');
            $table->foreignUlid('patient_id')->nullable()->constrained();
            $table->foreignUlid('prescription_id')->nullable()->constrained();
            $table->unsignedBigInteger('prescriber_staff_id')->nullable();
            $table->unsignedBigInteger('pharmacist_staff_id')->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('recorded_at');
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->string('collection_code', 4)->nullable();
            $table->timestamp('ready_for_collection_at')->nullable();
            $table->string('collected_by_name')->nullable();
            $table->string('collected_by_id_number', 20)->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->string('discharge_override_reason')->nullable();
            $table->unsignedBigInteger('discharge_override_by')->nullable();
        });

        Schema::create('quotes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('visit_id')->constrained();
            $table->foreignUlid('patient_id')->constrained();
            $table->json('lines');
            $table->unsignedInteger('total_cents');
            $table->string('status', 12)->default('draft');
            $table->string('preauth_number', 40)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('remittances', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('claim_id')->constrained();
            $table->string('scheme_reference', 60)->unique();
            $table->unsignedInteger('paid_cents');
            $table->string('message')->nullable();
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remittances');
        Schema::dropIfExists('quotes');
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropColumn(['collection_code', 'ready_for_collection_at', 'collected_by_name', 'collected_by_id_number', 'collected_at', 'discharge_override_reason', 'discharge_override_by']);
        });
        foreach (['scheduled_register', 'owing_items', 'dispensings', 'stock_batches', 'stock_items'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
