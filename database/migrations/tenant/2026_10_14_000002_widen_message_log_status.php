<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message statuses now include not_in_package, over_allowance and suppressed_test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_log', function (Blueprint $table): void {
            $table->string('status', 24)->change();
        });
    }

    public function down(): void
    {
        Schema::table('message_log', function (Blueprint $table): void {
            $table->string('status', 12)->change();
        });
    }
};
