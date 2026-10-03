<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online consults: availability per doctor and mode, price table, paid
 * bookings with a payment hold, refund tasks, chat threads and messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tele_availability', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->index();
            $table->string('mode', 8);
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
        });

        Schema::create('tele_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->index();
            $table->date('date');
            $table->string('type', 8);
            $table->string('mode', 8)->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('note')->nullable();
        });

        Schema::create('tele_prices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('mode', 8);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedInteger('price_cents');
            $table->unique(['staff_id', 'mode', 'duration_minutes']);
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedInteger('price_cents')->nullable();
            $table->string('payment_status', 12)->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->foreignUlid('visit_id')->nullable();
        });

        Schema::table('tele_sessions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('extension_minutes')->default(0);
            $table->unsignedBigInteger('pending_extension_payment')->nullable();
        });

        Schema::create('refund_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained();
            $table->foreignUlid('appointment_id')->nullable()->constrained();
            $table->unsignedInteger('amount_cents');
            $table->string('reason');
            $table->timestamp('due_at');
            $table->timestamp('done_at')->nullable();
            $table->string('reference')->nullable();
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->timestamps();
        });

        Schema::create('chat_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('appointment_id')->nullable()->constrained();
            $table->foreignUlid('patient_id')->constrained();
            $table->unsignedBigInteger('doctor_staff_id');
            $table->string('kind', 10);
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->timestamps();
            $table->index(['patient_id', 'kind']);
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_thread_id')->constrained()->cascadeOnDelete();
            $table->string('sender', 8);
            $table->text('body');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_threads');
        Schema::dropIfExists('refund_tasks');
        Schema::table('tele_sessions', fn (Blueprint $t) => $t->dropColumn(['extension_minutes', 'pending_extension_payment']));
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn(['duration_minutes', 'price_cents', 'payment_status', 'hold_expires_at', 'visit_id']));
        Schema::dropIfExists('tele_prices');
        Schema::dropIfExists('tele_exceptions');
        Schema::dropIfExists('tele_availability');
    }
};
