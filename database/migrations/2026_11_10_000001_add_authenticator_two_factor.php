<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authenticator-app sign-in (TOTP, RFC 6238): an encrypted secret, recovery codes (hashed, encrypted),
 * and the last accepted time step so a code can never be reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->unsignedBigInteger('totp_last_step')->nullable();
            $table->text('recovery_codes')->nullable();
        });
        Schema::table('login_challenges', function (Blueprint $table): void {
            $table->string('method', 20)->default('message');
        });
    }

    public function down(): void
    {
        Schema::table('login_challenges', fn (Blueprint $t) => $t->dropColumn('method'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['totp_secret', 'totp_confirmed_at', 'totp_last_step', 'recovery_codes']));
    }
};
