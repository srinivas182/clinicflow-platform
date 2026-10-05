<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI scribe sessions. Audio is deleted straight after transcription; the
 * transcript and draft are encrypted and removed after 30 days. Only what the
 * doctor accepts becomes part of the consultation note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scribe_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('consultation_id')->constrained();
            $table->foreignUlid('patient_id')->constrained();
            $table->unsignedBigInteger('staff_id');
            $table->timestamp('consent_at');
            $table->string('status', 12)->default('created');
            $table->unsignedInteger('seconds')->default(0);
            $table->unsignedSmallInteger('minutes_billed')->default(0);
            $table->unsignedInteger('wallet_cents')->default(0);
            $table->longText('transcript')->nullable();
            $table->longText('draft')->nullable();
            $table->string('speech_driver', 12)->nullable();
            $table->string('notes_driver', 12)->nullable();
            $table->string('error')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::table('patients', function (Blueprint $table): void {
            $table->timestamp('ai_scribe_declined_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('patients', fn (Blueprint $t) => $t->dropColumn('ai_scribe_declined_at'));
        Schema::dropIfExists('scribe_sessions');
    }
};
