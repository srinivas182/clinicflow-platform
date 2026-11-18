<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One WhatsApp template per message per language (beta #247): a translated template no longer
 * replaces the English one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table): void {
            $table->dropUnique(['message_key']);
            $table->unique(['message_key', 'language']);
        });
    }

    public function down(): void
    {
        // Back to one template per message: keep the English (or first) one.
        $keep = DB::table('whatsapp_templates')->orderByRaw("language = 'en' desc")->orderBy('id')->get(['id', 'message_key'])->unique('message_key')->pluck('id');
        DB::table('whatsapp_templates')->whereNotIn('id', $keep)->delete();
        Schema::table('whatsapp_templates', function (Blueprint $table): void {
            $table->dropUnique(['message_key', 'language']);
            $table->unique('message_key');
        });
    }
};
