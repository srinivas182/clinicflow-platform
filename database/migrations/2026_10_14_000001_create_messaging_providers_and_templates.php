<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-owned SMS and email accounts (super admin only), platform default
 * message templates, and per-channel monthly usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('driver', 16)->unique();
            $table->string('channel', 8);
            $table->string('mode', 8)->default('test');
            $table->boolean('enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->text('credentials')->nullable();
            $table->string('sender', 120)->nullable();
            $table->json('test_recipients')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 48);
            $table->string('channel', 8);
            $table->string('language', 4);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['key', 'channel', 'language']);
        });

        Schema::table('message_usage', function (Blueprint $table): void {
            $table->unsignedInteger('sms_units')->default(0)->after('units');
            $table->unsignedInteger('email_units')->default(0)->after('sms_units');
            $table->unsignedTinyInteger('alert_level')->default(0)->after('email_units');
        });
    }

    public function down(): void
    {
        Schema::table('message_usage', function (Blueprint $table): void {
            $table->dropColumn(['sms_units', 'email_units', 'alert_level']);
        });
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('messaging_providers');
    }
};
