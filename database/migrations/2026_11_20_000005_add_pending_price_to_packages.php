<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price rise for a package with paying practices takes effect 30 days after they are told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->unsignedInteger('pending_price_monthly_cents')->nullable()->after('price_annual_cents');
            $table->date('pending_price_from')->nullable()->after('pending_price_monthly_cents');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropColumn(['pending_price_monthly_cents', 'pending_price_from']);
        });
    }
};
