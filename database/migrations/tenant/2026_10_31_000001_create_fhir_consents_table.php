<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-patient consent for a connected system (an API key with fhir:read)
 * to read chosen categories of the patient's record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fhir_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('patient_id')->constrained();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->json('categories');
            $table->string('granted_via', 10);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'api_key_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fhir_consents');
    }
};
