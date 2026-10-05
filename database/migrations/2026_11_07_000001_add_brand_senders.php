<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand senders: a verified email "from" address on the brand's own domain, and an approved SMS sender name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->string('email_from')->nullable();
            $table->string('email_token', 40)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('sms_sender', 11)->nullable();
            $table->boolean('sms_sender_approved')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('brands', fn (Blueprint $t) => $t->dropColumn(['email_from', 'email_token', 'email_verified_at', 'sms_sender', 'sms_sender_approved']));
    }
};
