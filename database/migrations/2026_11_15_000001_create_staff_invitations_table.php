<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations for practices to add their own staff. Single-use links valid for 7 days; only a hash
 * of the link token is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('name', 120);
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('role', 32);
            $table->json('branch_ids')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('invited_by');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
