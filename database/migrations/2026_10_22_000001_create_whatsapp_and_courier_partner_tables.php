<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform database: the WhatsApp supplier (Meta Cloud API, Twilio or
 * Clickatell — one active), WhatsApp template approvals, and the courier
 * partners the super admin makes available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('driver', 12)->unique();
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable();
            $table->string('sender', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('message_key', 60)->unique();
            $table->string('template_name', 100);
            $table->string('language', 8)->default('en');
            $table->string('category', 16);
            $table->string('status', 10)->default('pending');
            $table->string('rejected_reason')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('courier_partners', function (Blueprint $table): void {
            $table->id();
            $table->string('driver', 12)->unique();
            $table->boolean('enabled')->default(false);
            $table->boolean('api_ready')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_partners');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('whatsapp_providers');
    }
};
