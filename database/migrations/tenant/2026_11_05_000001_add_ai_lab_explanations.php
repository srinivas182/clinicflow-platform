<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plain-language lab explanations drafted with AI and edited/released by the doctor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_orders', function (Blueprint $table): void {
            $table->boolean('note_ai_assisted')->default(false);
            $table->unsignedBigInteger('note_reviewed_by')->nullable();
        });

        Schema::create('lab_explanations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('lab_order_id')->constrained();
            $table->unsignedBigInteger('staff_id');
            $table->unsignedSmallInteger('minutes');
            $table->unsignedInteger('wallet_cents');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_explanations');
        Schema::table('lab_orders', fn (Blueprint $t) => $t->dropColumn(['note_ai_assisted', 'note_reviewed_by']));
    }
};
