<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient register for one provider. Records are never deleted (HPCSA retention);
 * corrections keep history through the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('first_names');
            $table->string('surname');
            $table->string('id_type', 16);
            $table->text('id_number')->nullable();
            $table->string('id_number_hash', 64)->nullable()->unique();
            $table->string('passport_country', 2)->nullable();
            $table->date('date_of_birth');
            $table->string('sex', 8)->nullable();
            $table->string('cell', 10)->nullable()->index();
            $table->boolean('no_cell')->default(false);
            $table->string('email')->nullable();
            $table->string('preferred_language', 8)->default('en');
            $table->string('preferred_channel', 16)->default('sms');
            $table->text('address')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_relationship', 32)->nullable();
            $table->string('guardian_cell', 10)->nullable();
            $table->string('medical_aid_scheme')->nullable();
            $table->string('medical_aid_plan')->nullable();
            $table->string('medical_aid_number')->nullable();
            $table->string('medical_aid_dependant_code', 4)->nullable();
            $table->string('hub_identity_id')->nullable()->index();
            $table->unsignedBigInteger('registered_by')->nullable();
            $table->timestamps();

            $table->index(['surname', 'first_names']);
        });

        Schema::create('patient_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('given_by', 16);
            $table->boolean('maturity_confirmed')->default(false);
            $table->timestamp('granted_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->unsignedBigInteger('captured_by')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_consents');
        Schema::dropIfExists('patients');
    }
};
