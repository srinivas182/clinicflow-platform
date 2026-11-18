<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texts approved by a reviewer who was later removed go on a re-check list (beta #205); they stay
 * live until another reviewer approves them again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table): void {
            $table->string('recheck_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table): void {
            $table->dropColumn('recheck_reason');
        });
    }
};
