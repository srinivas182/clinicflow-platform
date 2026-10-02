<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's own merchant accounts (patient payments). Credentials are
 * encrypted and stored only in this provider's database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('gateway', 16)->unique();
            $table->string('mode', 8)->default('test');
            $table->boolean('enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->text('credentials')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('checkout_token', 64)->nullable()->unique()->after('gateway');
            $table->string('gateway_reference')->nullable()->after('checkout_token');
            $table->string('gateway_mode', 8)->nullable()->after('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['checkout_token']);
            $table->dropColumn(['checkout_token', 'gateway_reference', 'gateway_mode']);
        });
        Schema::dropIfExists('payment_gateway_configs');
    }
};
