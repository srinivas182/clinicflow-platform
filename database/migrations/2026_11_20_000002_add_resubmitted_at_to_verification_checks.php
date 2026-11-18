<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A practice can correct a rejected registration check and send it back for review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_checks', function (Blueprint $table): void {
            $table->timestamp('resubmitted_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('verification_checks', function (Blueprint $table): void {
            $table->dropColumn('resubmitted_at');
        });
    }
};
