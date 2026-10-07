<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who opened which patient record, and when — used to spot unusual access (e.g. one person
 * opening 100+ different patients in an hour) and to cap scripted bulk viewing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_views', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->foreignUlid('patient_id')->constrained()->cascadeOnDelete();
            $table->string('route', 60);
            $table->timestamp('created_at');
            $table->index(['staff_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_views');
    }
};
