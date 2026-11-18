<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reseller's commission can be changed for new referrals only: each referral keeps the
 * commission terms in force when the practice signed up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_referrals', function (Blueprint $table): void {
            $table->decimal('commission_percent', 5, 2)->nullable()->after('referred_at');
            $table->unsignedSmallInteger('commission_months')->nullable()->after('commission_percent');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_referrals', function (Blueprint $table): void {
            $table->dropColumn(['commission_percent', 'commission_months']);
        });
    }
};
