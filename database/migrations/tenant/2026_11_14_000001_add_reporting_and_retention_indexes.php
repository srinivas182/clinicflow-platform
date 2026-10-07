<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for reports, analytics and retention: date and status filters on large tables.
 * Each index is added only if missing, so this is safe on any installation.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    private array $indexes = [
        'invoices' => [['created_at'], ['status', 'created_at']],
        'payments' => [['status', 'created_at']],
        'appointments' => [['starts_at', 'status']],
        'visits' => [['doctor_id', 'visit_date']],
        'lab_orders' => [['created_at']],
        'claims' => [['created_at']],
        'prescriptions' => [['signed_at'], ['prescriber_staff_id', 'signed_at']],
        'message_log' => [['sent_at'], ['related_type', 'related_id']],
        'record_views' => [['created_at']],
        'webhook_deliveries' => [['created_at']],
        'activity_log' => [['created_at']],
        'patient_access_log' => [['created_at']],
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
