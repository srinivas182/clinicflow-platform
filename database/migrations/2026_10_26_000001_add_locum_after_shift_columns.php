<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Locum marketplace after the shift: hours, shift invoice, cancellations,
 * private "would book again", alerts and reminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locum_shifts', function (Blueprint $table): void {
            $table->string('area', 60)->nullable()->after('title');
            $table->dateTime('worked_start')->nullable();
            $table->dateTime('worked_end')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->string('hours_status', 10)->nullable();
            $table->string('hours_note')->nullable();
            $table->string('invoice_number', 20)->nullable()->unique();
            $table->unsignedInteger('invoice_total_cents')->nullable();
            $table->timestamp('invoice_paid_at')->nullable();
            $table->string('cancelled_by', 10)->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('late_cancellation')->default(false);
            $table->boolean('rebook')->nullable();
            $table->string('rebook_note')->nullable();
            $table->timestamp('reminded_at')->nullable();
        });

        Schema::table('locum_profiles', function (Blueprint $table): void {
            $table->string('vat_number', 12)->nullable();
            $table->boolean('alerts_email')->default(true);
            $table->boolean('alerts_sms')->default(false);
        });

        Schema::create('locum_alerts', function (Blueprint $table): void {
            $table->foreignId('locum_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('locum_shift_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at');
            $table->primary(['locum_profile_id', 'locum_shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locum_alerts');
        Schema::table('locum_profiles', fn (Blueprint $t) => $t->dropColumn(['vat_number', 'alerts_email', 'alerts_sms']));
        Schema::table('locum_shifts', fn (Blueprint $t) => $t->dropColumn(['area', 'worked_start', 'worked_end', 'break_minutes', 'hours_status', 'hours_note', 'invoice_number',
            'invoice_total_cents', 'invoice_paid_at', 'cancelled_by', 'cancel_reason', 'cancelled_at', 'late_cancellation', 'rebook', 'rebook_note', 'reminded_at']));
    }
};
