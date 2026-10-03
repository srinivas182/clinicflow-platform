<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patients' WhatsApp opt-in, the practice's courier accounts and deliveries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->timestamp('whatsapp_opt_in_at')->nullable();
        });

        Schema::create('courier_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('driver', 12)->unique();
            $table->string('account_ref')->nullable();
            $table->text('credentials')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained();
            $table->foreignUlid('visit_id')->nullable()->constrained();
            $table->string('driver', 12);
            $table->string('address');
            $table->string('status', 12)->default('requested');
            $table->string('tracking_number')->nullable();
            $table->unsignedInteger('order_value_cents');
            $table->unsignedInteger('fee_cents');
            $table->string('payer', 8);
            $table->string('proof_code_hash')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('courier_accounts');
        Schema::table('patients', fn (Blueprint $t) => $t->dropColumn('whatsapp_opt_in_at'));
    }
};
