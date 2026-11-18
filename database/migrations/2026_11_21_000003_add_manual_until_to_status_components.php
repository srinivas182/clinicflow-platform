<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A status set by hand expires (default 4 hours) so a forgotten override never hides the real state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_components', function (Blueprint $table): void {
            $table->timestamp('manual_until')->nullable()->after('manual');
        });
    }

    public function down(): void
    {
        Schema::table('status_components', function (Blueprint $table): void {
            $table->dropColumn('manual_until');
        });
    }
};
