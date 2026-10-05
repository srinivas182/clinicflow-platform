<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI scribe (platform database): speech-to-text and note-writing providers the
 * super admin manages, and each practice's monthly minutes used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('driver', 12)->unique();
            $table->string('kind', 8);
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable();
            $table->unsignedInteger('cost_per_minute_millicents')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_usage', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('period', 7);
            $table->unsignedInteger('minutes_included_used')->default(0);
            $table->unsignedInteger('minutes_wallet')->default(0);
            $table->unsignedInteger('wallet_cents')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_providers');
    }
};
