<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website media library and patient feedback (reviews).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('filename');
            $table->string('mime', 40);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('alt', 200)->default('');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('feedback_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('visit_id')->unique()->constrained();
            $table->foreignUlid('patient_id')->constrained();
            $table->string('token', 48)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feedback_request_id')->unique()->constrained();
            $table->foreignUlid('patient_id')->constrained();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('public_ok')->default(false);
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->string('flag_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('feedback_requests');
        Schema::dropIfExists('media');
    }
};
