<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master lab catalogue (Platform database): LOINC code, sample type, result
 * type, plausibility limits and reference ranges by sex and age. Labs copy
 * tests from here into their own catalogue and adjust them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_tests', function (Blueprint $table): void {
            $table->string('loinc', 12)->nullable();
            $table->string('sample_type', 40)->default('Serum');
            $table->string('result_type', 8)->default('numeric');
            $table->json('choices')->nullable();
            $table->unsignedTinyInteger('decimals')->default(1);
            $table->decimal('plausible_min', 10, 2)->nullable();
            $table->decimal('plausible_max', 10, 2)->nullable();
            $table->unsignedSmallInteger('turnaround_hours')->default(24);
        });

        Schema::create('lab_test_ranges', function (Blueprint $table): void {
            $table->id();
            $table->string('test_code', 16)->index();
            $table->string('sex', 6)->nullable();
            $table->unsignedSmallInteger('age_min_months')->default(0);
            $table->unsignedSmallInteger('age_max_months')->default(1500);
            $table->decimal('ref_low', 10, 2)->nullable();
            $table->decimal('ref_high', 10, 2)->nullable();
            $table->decimal('critical_low', 10, 2)->nullable();
            $table->decimal('critical_high', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_test_ranges');
        Schema::table('lab_tests', fn (Blueprint $t) => $t->dropColumn(['loinc', 'sample_type', 'result_type', 'choices', 'decimals', 'plausible_min', 'plausible_max', 'turnaround_hours']));
    }
};
