<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in history per user (shown on the security page; used to spot sign-ins from a new device).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('device', 64);
            $table->boolean('new_device')->default(false);
            $table->timestamp('created_at');
            $table->index(['user_id', 'device']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
