<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform indexes for retention clean-up (date filters).
 * Each index is added only if missing, so this is safe on any installation.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    private array $indexes = [
        'login_events' => [['created_at']],
        'login_challenges' => [['created_at']],
        'activity_log' => [['created_at']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $sets) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($sets as $columns) {
                $name = $table.'_'.implode('_', $columns).'_perf';
                if (array_diff($columns, Schema::getColumnListing($table)) !== [] || Schema::hasIndex($table, $columns)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $sets) {
            foreach ($sets as $columns) {
                $name = $table.'_'.implode('_', $columns).'_perf';
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
