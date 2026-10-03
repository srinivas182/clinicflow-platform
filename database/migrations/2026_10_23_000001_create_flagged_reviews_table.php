<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews a practice reports as abusive, for the super admin to decide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flagged_reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('review_id');
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->string('reason');
            $table->string('decision', 10)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'review_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flagged_reviews');
    }
};
