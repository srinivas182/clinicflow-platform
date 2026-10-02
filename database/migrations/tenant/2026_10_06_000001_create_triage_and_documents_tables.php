<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Triage, allergies, doctor routing fields, document templates and issued documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->timestamp('called_at')->nullable()->after('triage_colour');
            $table->foreignId('room_id')->nullable()->after('called_at')->constrained()->nullOnDelete();
            $table->timestamp('alert_accepted_at')->nullable()->after('room_id');
        });

        Schema::create('triage_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('visit_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('bp_systolic');
            $table->unsignedSmallInteger('bp_diastolic');
            $table->unsignedSmallInteger('pulse');
            $table->decimal('temperature', 4, 1);
            $table->unsignedTinyInteger('spo2')->nullable();
            $table->unsignedTinyInteger('resp_rate')->nullable();
            $table->decimal('glucose', 4, 1)->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->unsignedTinyInteger('pain_score')->nullable();
            $table->string('suggested_colour', 8);
            $table->string('colour', 8);
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('nurse_staff_id')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('allergies', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained();
            $table->string('substance', 120);
            $table->string('reaction', 255)->nullable();
            $table->string('status', 16)->default('active');
            $table->string('removed_reason', 255)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('removed_by')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
        });

        Schema::create('document_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 24);
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(false);
            $table->longText('body');
            $table->string('paper', 4)->default('A4');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['type', 'version']);
        });

        Schema::create('issued_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 24);
            $table->foreignId('document_template_id')->constrained();
            $table->unsignedInteger('template_version');
            $table->string('subject_type');
            $table->string('subject_id', 36);
            $table->string('file_path');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_documents');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('allergies');
        Schema::dropIfExists('triage_records');
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('room_id');
            $table->dropColumn(['called_at', 'alert_accepted_at']);
        });
    }
};
