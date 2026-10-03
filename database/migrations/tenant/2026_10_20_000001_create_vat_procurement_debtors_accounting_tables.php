<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VAT on invoices and credit notes, procurement (suppliers, purchase orders),
 * stock adjustments, debtors (statements, bad-debt write-offs) and the
 * practice's accounting connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedInteger('vat_cents')->default(0)->after('credited_cents');
            $table->decimal('vat_rate', 5, 2)->default(0)->after('vat_cents');
            $table->boolean('tax_invoice')->default(false)->after('vat_rate');
        });
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->unsignedInteger('vat_cents')->default(0)->after('total_cents');
        });
        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->unsignedInteger('vat_cents')->default(0)->after('amount_cents');
        });

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('vat_number', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('supplier_id')->constrained();
            $table->string('status', 10)->default('draft');
            $table->unsignedInteger('total_cents')->default(0);
            $table->unsignedInteger('vat_cents')->default(0);
            $table->unsignedBigInteger('ordered_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('medicine_id');
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_cost_cents');
            $table->unsignedInteger('received_quantity')->default(0);
        });

        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_item_id')->constrained();
            $table->unsignedBigInteger('stock_batch_id')->nullable();
            $table->string('kind', 12);
            $table->integer('quantity');
            $table->string('reason');
            $table->unsignedBigInteger('by');
            $table->timestamp('created_at');
        });

        Schema::create('debt_write_offs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('invoice_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->string('reason');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('credit_note_id')->nullable();
            $table->timestamps();
        });

        Schema::create('statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained();
            $table->string('period', 7);
            $table->unsignedInteger('balance_cents');
            $table->timestamp('sent_at');
            $table->unique(['patient_id', 'period']);
        });

        Schema::create('accounting_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('driver', 8)->unique();
            $table->boolean('enabled')->default(false);
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('org_id')->nullable();
            $table->string('auto_export', 8)->default('off');
            $table->json('account_map')->nullable();
            $table->date('exported_until')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['accounting_connections', 'statements', 'debt_write_offs', 'stock_adjustments', 'purchase_order_lines', 'purchase_orders', 'suppliers'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('credit_notes', fn (Blueprint $t) => $t->dropColumn('vat_cents'));
        Schema::table('invoice_lines', fn (Blueprint $t) => $t->dropColumn('vat_cents'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['vat_cents', 'vat_rate', 'tax_invoice']));
    }
};
