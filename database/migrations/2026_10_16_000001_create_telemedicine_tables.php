<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform database: LiveKit configurations (Cloud or self-hosted; one active)
 * and the Telemedicine add-on on subscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('driver', 16)->unique();
            $table->string('mode', 8)->default('test');
            $table->string('url')->nullable();
            $table->string('api_key')->nullable();
            $table->text('api_secret')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->json('addons')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn('addons'));
        Schema::dropIfExists('video_configs');
    }
};
