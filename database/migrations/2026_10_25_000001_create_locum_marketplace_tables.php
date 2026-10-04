<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Locum marketplace (platform database): locum profiles and documents,
 * practice shifts, applications, and optional booking fees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locum_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('hpcsa_number', 20);
            $table->string('qualifications', 500);
            $table->json('languages');
            $table->json('areas');
            $table->unsignedInteger('hourly_rate_cents')->nullable();
            $table->text('bio')->nullable();
            $table->string('status', 10)->default('pending');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();
        });

        Schema::create('locum_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('locum_profile_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('path');
            $table->string('filename');
            $table->date('expires_on')->nullable();
            $table->timestamps();
        });

        Schema::create('locum_shifts', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('title');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('rate_cents');
            $table->string('rate_basis', 6)->default('hour');
            $table->text('requirements')->nullable();
            $table->string('status', 10)->default('open');
            $table->foreignId('invited_profile_id')->nullable()->constrained('locum_profiles')->nullOnDelete();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['status', 'starts_at']);
        });

        Schema::create('locum_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('locum_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('locum_profile_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('applied');
            $table->string('message', 500)->nullable();
            $table->timestamps();
            $table->unique(['locum_shift_id', 'locum_profile_id']);
        });

        Schema::create('locum_fees', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('locum_shift_id')->unique()->constrained();
            $table->unsignedInteger('amount_cents');
            $table->unsignedBigInteger('subscription_invoice_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['locum_fees', 'locum_applications', 'locum_shifts', 'locum_documents', 'locum_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
