<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-debit for subscriptions: saved-card mandates (gateway tokens only —
 * never card numbers), consent on the invoice that set them up, and every
 * debit attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_mandates', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('gateway', 16);
            $table->string('mode', 8);
            $table->text('token');
            $table->string('token_hash', 64)->index();
            $table->string('email')->nullable();
            $table->string('card_brand', 24)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('card_expiry', 7)->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('consented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('consented_at');
            $table->unsignedTinyInteger('failure_count')->default(0);
            $table->timestamp('last_charged_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_note')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('subscription_debit_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_mandate_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 80)->unique();
            $table->string('outcome', 16);
            $table->string('message')->nullable();
            $table->timestamp('attempted_at');
        });

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->boolean('save_card')->default(false)->after('checkout_token');
            $table->foreignId('save_card_consented_by')->nullable()->after('save_card');
            $table->string('save_card_consent_ip', 45)->nullable()->after('save_card_consented_by');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropColumn(['save_card', 'save_card_consented_by', 'save_card_consent_ip']);
        });
        Schema::dropIfExists('subscription_debit_attempts');
        Schema::dropIfExists('billing_mandates');
    }
};
