<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remote consults: the scribe waits for the patient to agree on their own screen,
 * so a session can exist before consent is given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scribe_sessions', function (Blueprint $table): void {
            $table->timestamp('consent_at')->nullable()->change();
            $table->string('source', 8)->default('room');
            $table->foreignUlid('appointment_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('scribe_sessions', function (Blueprint $table): void {
            $table->dropColumn(['source', 'appointment_id']);
        });
    }
};
