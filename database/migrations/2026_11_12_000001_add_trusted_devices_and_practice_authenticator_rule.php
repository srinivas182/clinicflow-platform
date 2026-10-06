<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trusted devices (skip the sign-in code for 30 days; only a hash of the device token is kept)
 * and a practice-wide rule requiring an authenticator app for all staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('label', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('require_authenticator')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('require_authenticator'));
        Schema::dropIfExists('trusted_devices');
    }
};
