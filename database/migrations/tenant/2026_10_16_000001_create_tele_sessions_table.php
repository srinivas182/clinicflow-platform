<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One online consult room per appointment; joins and leaves come from LiveKit webhooks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tele_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('appointment_id')->unique()->constrained();
            $table->string('room_name', 100)->unique();
            $table->string('status', 12)->default('scheduled');
            $table->string('wallet_reference', 80);
            $table->timestamp('doctor_joined_at')->nullable();
            $table->timestamp('patient_joined_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('connected_seconds')->default(0);
            $table->unsignedInteger('charged_minutes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tele_sessions');
    }
};
