<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POPIA: how a patient agreed to WhatsApp messages (in person, signed form, by message or in the
 * portal) and which staff member recorded it, next to when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('whatsapp_opt_in_method', 16)->nullable()->after('whatsapp_opt_in_at');
            $table->unsignedBigInteger('whatsapp_opt_in_by')->nullable()->after('whatsapp_opt_in_method');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn(['whatsapp_opt_in_method', 'whatsapp_opt_in_by']);
        });
    }
};
