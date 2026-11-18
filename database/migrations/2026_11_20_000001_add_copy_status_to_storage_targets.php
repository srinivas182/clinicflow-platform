<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Switching storage safely: a target can only be activated after existing files were copied into
 * it and checked. Records the copy's status, counts, source and time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_targets', function (Blueprint $table): void {
            $table->string('copy_status', 12)->nullable()->after('last_error');
            $table->json('copy_progress')->nullable()->after('copy_status');
            $table->string('copy_source', 20)->nullable()->after('copy_progress');
            $table->timestamp('copied_at')->nullable()->after('copy_source');
        });
    }

    public function down(): void
    {
        Schema::table('storage_targets', function (Blueprint $table): void {
            $table->dropColumn(['copy_status', 'copy_progress', 'copy_source', 'copied_at']);
        });
    }
};
