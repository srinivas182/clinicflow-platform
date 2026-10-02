<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared clinical reference data (Platform database): ICD-10 codes and the
 * medicine catalogue with interaction rules. The demo set is replaced by the
 * licensed drug database (MIMS or MediKredit) behind the DrugDatabase contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icd10_codes', function (Blueprint $table): void {
            $table->string('code', 10)->primary();
            $table->string('description');
            $table->boolean('valid_primary')->default(true);
            $table->index('description');
        });

        Schema::create('medicines', function (Blueprint $table): void {
            $table->id();
            $table->string('nappi_code', 12)->unique();
            $table->string('name');
            $table->string('strength', 40);
            $table->string('form', 24);
            $table->string('schedule', 4);
            $table->json('ingredients');
            $table->json('allergy_classes');
            $table->string('default_dose', 120)->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->index('name');
        });

        Schema::create('drug_interactions', function (Blueprint $table): void {
            $table->id();
            $table->string('ingredient_a', 60);
            $table->string('ingredient_b', 60);
            $table->string('severity', 12);
            $table->string('message');
            $table->unique(['ingredient_a', 'ingredient_b']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drug_interactions');
        Schema::dropIfExists('medicines');
        Schema::dropIfExists('icd10_codes');
    }
};
