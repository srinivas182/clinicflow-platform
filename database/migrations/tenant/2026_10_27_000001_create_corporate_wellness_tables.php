<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corporate wellness: employer accounts, screening events, employee
 * self-registrations (with their own consent) and screening results.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('billing_address', 500)->nullable();
            $table->string('vat_number', 12)->nullable();
            $table->unsignedInteger('rate_cents');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('wellness_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_account_id')->constrained();
            $table->string('title');
            $table->string('location');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('slot_minutes')->default(15);
            $table->unsignedSmallInteger('per_slot')->default(2);
            $table->json('services');
            $table->string('token', 40)->unique();
            $table->string('status', 10)->default('open');
            $table->timestamps();
        });

        Schema::create('wellness_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wellness_event_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('patient_id')->constrained();
            $table->dateTime('slot_at');
            $table->timestamp('consented_at');
            $table->string('status', 10)->default('registered');
            $table->timestamps();
            $table->unique(['wellness_event_id', 'patient_id']);
        });

        Schema::create('wellness_screenings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wellness_registration_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('bp_systolic')->nullable();
            $table->unsignedSmallInteger('bp_diastolic')->nullable();
            $table->decimal('glucose', 4, 1)->nullable();
            $table->decimal('cholesterol', 4, 1)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->decimal('bmi', 4, 1)->nullable();
            $table->boolean('flu_vaccinated')->default(false);
            $table->json('flags');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['wellness_screenings', 'wellness_registrations', 'wellness_events', 'corporate_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
