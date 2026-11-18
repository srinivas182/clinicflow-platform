<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saving a card for automatic payment before any invoice (decision on beta rows 2 and 12):
 * a R1 card check, refunded straight away, with the owner's consent recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('checkout_token', 64)->unique();
            $table->unsignedInteger('amount_cents')->default(100);
            $table->string('status', 12)->default('open');
            $table->string('gateway', 20)->nullable();
            $table->string('gateway_reference')->nullable();
            $table->unsignedBigInteger('consented_by')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_checks');
    }
};
