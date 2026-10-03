<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each lab's own test catalogue (templates), panels and approved range
 * changes; lab orders gain the network, classification and release fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_catalog_tests', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->string('loinc', 12)->nullable();
            $table->string('sample_type', 40);
            $table->string('result_type', 8);
            $table->json('choices')->nullable();
            $table->string('unit', 24)->nullable();
            $table->unsignedTinyInteger('decimals')->default(1);
            $table->decimal('plausible_min', 10, 2)->nullable();
            $table->decimal('plausible_max', 10, 2)->nullable();
            $table->unsignedInteger('price_cents');
            $table->unsignedSmallInteger('turnaround_hours')->default(24);
            $table->boolean('home_collection')->default(true);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('lab_catalog_ranges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_catalog_test_id')->constrained()->cascadeOnDelete();
            $table->string('sex', 6)->nullable();
            $table->unsignedSmallInteger('age_min_months')->default(0);
            $table->unsignedSmallInteger('age_max_months')->default(1500);
            $table->decimal('ref_low', 10, 2)->nullable();
            $table->decimal('ref_high', 10, 2)->nullable();
            $table->decimal('critical_low', 10, 2)->nullable();
            $table->decimal('critical_high', 10, 2)->nullable();
        });

        Schema::create('lab_panels', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name');
            $table->unsignedInteger('price_cents');
            $table->json('test_codes');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('lab_range_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_catalog_test_id')->constrained()->cascadeOnDelete();
            $table->json('ranges');
            $table->unsignedBigInteger('proposed_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::table('lab_orders', function (Blueprint $table): void {
            $table->foreignUlid('visit_id')->nullable()->change();
            $table->unsignedBigInteger('ordering_staff_id')->nullable()->change();
            $table->string('source', 12)->default('in_house');
            $table->string('lab_tenant_id')->nullable();
            $table->string('hub_order_id', 26)->nullable()->index();
            $table->string('classification', 12)->nullable();
            $table->string('doctor_action', 10)->nullable();
            $table->text('doctor_note')->nullable();
            $table->timestamp('patient_requested_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('auto_released_at')->nullable();
            $table->string('report_path')->nullable();
            $table->json('home_collection')->nullable();
            $table->unsignedBigInteger('collector_staff_id')->nullable();
        });

        Schema::table('lab_results', function (Blueprint $table): void {
            $table->string('result_text')->nullable();
            $table->decimal('ref_low', 10, 2)->nullable();
            $table->decimal('ref_high', 10, 2)->nullable();
            $table->decimal('critical_low', 10, 2)->nullable();
            $table->decimal('critical_high', 10, 2)->nullable();
            $table->boolean('raised_by_lab')->default(false);
        });

        Schema::table('staff', function (Blueprint $table): void {
            $table->unsignedBigInteger('covering_staff_id')->nullable();
            $table->date('away_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staff', fn (Blueprint $t) => $t->dropColumn(['covering_staff_id', 'away_until']));
        Schema::table('lab_results', fn (Blueprint $t) => $t->dropColumn(['result_text', 'ref_low', 'ref_high', 'critical_low', 'critical_high', 'raised_by_lab']));
        Schema::table('lab_orders', fn (Blueprint $t) => $t->dropColumn(['source', 'lab_tenant_id', 'hub_order_id', 'classification', 'doctor_action', 'doctor_note', 'patient_requested_at', 'escalated_at', 'auto_released_at', 'report_path', 'home_collection', 'collector_staff_id']));
        foreach (['lab_range_changes', 'lab_panels', 'lab_catalog_ranges', 'lab_catalog_tests'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
