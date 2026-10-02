<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultations, signed and versioned prescriptions, eligibility checks and claims.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('visit_id')->unique()->constrained();
            $table->foreignUlid('patient_id')->constrained();
            $table->unsignedBigInteger('doctor_staff_id');
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->string('status', 16)->default('open');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('consultation_diagnoses', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('consultation_id')->constrained()->cascadeOnDelete();
            $table->string('icd10_code', 10);
            $table->string('description');
            $table->boolean('is_primary')->default(false);
        });

        Schema::create('prescriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('consultation_id')->constrained();
            $table->foreignUlid('patient_id')->constrained();
            $table->unsignedBigInteger('prescriber_staff_id');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUlid('previous_version_id')->nullable()->constrained('prescriptions');
            $table->string('status', 16)->default('draft');
            $table->timestamp('signed_at')->nullable();
            $table->string('signature_hash', 64)->nullable();
            $table->unsignedBigInteger('issued_document_id')->nullable();
            $table->string('change_reason')->nullable();
            $table->timestamps();

            $table->unique(['consultation_id', 'version']);
            $table->index(['patient_id', 'status']);
        });

        Schema::create('prescription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('prescription_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('medicine_id');
            $table->string('nappi_code', 12);
            $table->string('description');
            $table->string('schedule', 4);
            $table->string('dose', 120);
            $table->unsignedSmallInteger('quantity');
            $table->unsignedTinyInteger('repeats')->default(0);
            $table->string('override_reason')->nullable();
        });

        Schema::create('signing_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('prescription_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('staff_id');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('eligibility_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained();
            $table->foreignUlid('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scheme');
            $table->string('member_number', 40);
            $table->string('dependant_code', 4)->nullable();
            $table->string('status', 16);
            $table->string('message')->nullable();
            $table->string('switch', 24);
            $table->json('response')->nullable();
            $table->unsignedBigInteger('checked_by')->nullable();
            $table->timestamp('checked_at');
        });

        Schema::create('claims', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->unique()->constrained();
            $table->foreignUlid('patient_id')->constrained();
            $table->string('scheme');
            $table->string('member_number', 40);
            $table->string('dependant_code', 4)->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('total_cents');
            $table->unsignedTinyInteger('submissions')->default(0);
            $table->string('switch', 24)->nullable();
            $table->string('switch_reference')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('claim_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('claim_id')->constrained()->cascadeOnDelete();
            $table->string('tariff_code', 12)->nullable();
            $table->string('nappi_code', 12)->nullable();
            $table->json('icd10_codes');
            $table->string('description');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('amount_cents');
        });
    }

    public function down(): void
    {
        foreach (['claim_lines', 'claims', 'eligibility_checks', 'signing_challenges', 'prescription_items', 'prescriptions', 'consultation_diagnoses', 'consultations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
