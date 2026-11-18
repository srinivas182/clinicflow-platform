<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription reminders are sent once each: trial ending (7, 3 and 1 days before) and an
 * invoice falling due (3 days before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('trial_reminded_days')->nullable()->after('trial_ends_at');
        });
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->timestamp('reminded_at')->nullable()->after('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('trial_reminded_days');
        });
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropColumn('reminded_at');
        });
    }
};
