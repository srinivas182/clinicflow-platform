<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider wording overrides for package-included messages, and marketing opt-outs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_template_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 48);
            $table->string('channel', 8);
            $table->string('language', 4);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['key', 'channel', 'language']);
        });

        Schema::create('message_opt_outs', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 8);
            $table->string('recipient');
            $table->timestamp('opted_out_at');
            $table->unique(['channel', 'recipient']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_opt_outs');
        Schema::dropIfExists('message_template_overrides');
    }
};
