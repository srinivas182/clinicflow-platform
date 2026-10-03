<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals, clinician messaging (encrypted, append-only), the patient's
 * transparency log, break-glass reviews, problem list, chronic care,
 * recalls, immunisations, pregnancy and chronic medicine registration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('direction', 3);
            $table->foreignUlid('patient_id')->constrained();
            $table->foreignUlid('consultation_id')->nullable()->constrained();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('other_tenant_id')->nullable();
            $table->string('other_name');
            $table->string('specialty', 60);
            $table->string('urgency', 8);
            $table->text('reason');
            $table->json('shared_categories');
            $table->json('summary');
            $table->string('status', 12)->default('sent');
            $table->string('hub_referral_id', 26)->nullable();
            $table->dateTime('appointment_at')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('feedback_at')->nullable();
            $table->timestamps();
            $table->index(['direction', 'status']);
        });

        Schema::create('message_threads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('patient_id')->nullable()->constrained();
            $table->string('subject');
            $table->string('context_type', 12)->nullable();
            $table->string('context_id', 40)->nullable();
            $table->string('other_tenant_id')->nullable();
            $table->string('other_thread_id', 26)->nullable();
            $table->json('local_staff_ids');
            $table->boolean('urgent')->default(false);
            $table->timestamp('urgent_due_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('thread_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('message_thread_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sender_staff_id')->nullable();
            $table->string('sender_label');
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('shared_item', 80)->nullable();
            $table->unsignedBigInteger('corrects_id')->nullable();
            $table->boolean('visible_to_patient')->default(false);
            $table->timestamp('filed_at')->nullable();
            $table->json('read_by')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('patient_access_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained();
            $table->string('kind', 12);
            $table->string('summary');
            $table->timestamp('created_at');
            $table->index(['patient_id', 'created_at']);
        });

        Schema::create('break_glass_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained();
            $table->unsignedBigInteger('requested_by');
            $table->string('reason', 24);
            $table->text('details');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('problems', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained();
            $table->string('icd10_code', 10);
            $table->string('description');
            $table->boolean('chronic')->default(true);
            $table->string('status', 10)->default('active');
            $table->date('onset_date')->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamps();
        });

        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->boolean('chronic')->default(false);
        });

        Schema::create('recall_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('reason');
            $table->string('sex', 6)->nullable();
            $table->unsignedSmallInteger('age_min')->default(0);
            $table->unsignedSmallInteger('age_max')->default(120);
            $table->unsignedSmallInteger('interval_months');
            $table->boolean('active')->default(true);
            $table->boolean('reviewed')->default(false);
        });

        Schema::create('patient_recalls', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained();
            $table->foreignId('recall_rule_id')->constrained()->cascadeOnDelete();
            $table->date('due_on');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->unique(['patient_id', 'recall_rule_id', 'due_on']);
        });

        Schema::create('immunisation_schedule', function (Blueprint $table): void {
            $table->id();
            $table->string('vaccine', 40);
            $table->string('dose', 20);
            $table->unsignedSmallInteger('age_weeks');
            $table->boolean('reviewed')->default(false);
        });

        Schema::create('immunisations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained();
            $table->string('vaccine', 40);
            $table->string('dose', 20);
            $table->date('given_on');
            $table->string('batch', 40)->nullable();
            $table->unsignedBigInteger('given_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pregnancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained();
            $table->date('lmp');
            $table->date('edd');
            $table->string('status', 8)->default('active');
            $table->json('risk_flags')->nullable();
            $table->timestamps();
        });

        Schema::create('antenatal_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pregnancy_id')->constrained()->cascadeOnDelete();
            $table->date('visit_date');
            $table->unsignedTinyInteger('gestation_weeks');
            $table->string('bp', 9)->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->unsignedTinyInteger('fundal_height_cm')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('chronic_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained();
            $table->string('scheme');
            $table->string('icd10_code', 10);
            $table->json('medicines');
            $table->string('status', 10)->default('draft');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['chronic_registrations', 'antenatal_visits', 'pregnancies', 'immunisations', 'immunisation_schedule', 'patient_recalls', 'recall_rules',
            'problems', 'break_glass_reviews', 'patient_access_log', 'thread_messages', 'message_threads', 'referrals'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('prescriptions', fn (Blueprint $t) => $t->dropColumn('chronic'));
    }
};
