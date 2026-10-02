<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rooms, roster sessions and appointments for one provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('roster_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->index();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_type', 16)->default('in_person');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('slot_minutes')->default(15);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->foreign('staff_id')->references('id')->on('staff')->cascadeOnDelete();
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('appointments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('patient_id')->constrained();
            $table->unsignedBigInteger('staff_id');
            $table->foreignId('roster_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('consult_type', 16)->default('in_person');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 16)->default('booked');
            $table->string('reason')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->unsignedBigInteger('booked_by')->nullable();
            $table->timestamps();

            $table->foreign('staff_id')->references('id')->on('staff');
            $table->index(['staff_id', 'starts_at']);
            $table->index(['patient_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('roster_sessions');
        Schema::dropIfExists('rooms');
    }
};
