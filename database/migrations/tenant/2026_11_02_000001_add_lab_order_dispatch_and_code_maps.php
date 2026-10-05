<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outgoing lab orders to a connected lab system, and each lab system's own
 * test codes mapped to the practice's catalogue codes (used both ways).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('external_key_id')->nullable()->index();
            $table->timestamp('external_received_at')->nullable();
        });

        Schema::create('lab_code_maps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->string('external_code', 40);
            $table->string('test_code', 40);
            $table->timestamps();
            $table->unique(['api_key_id', 'external_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_code_maps');
        Schema::table('lab_orders', fn (Blueprint $t) => $t->dropColumn(['external_key_id', 'external_received_at']));
    }
};
