<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-scripts sent through the Network Hub from a prescriber to a pharmacy chosen by the patient.
 */
return new class extends Migration
{
    protected $connection = 'hub';

    public function up(): void
    {
        Schema::connection('hub')->create('hub_escripts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('identity_id')->constrained('hub_identities');
            $table->string('issuer_tenant_id');
            $table->string('prescription_id', 26);
            $table->string('consultation_id', 26);
            $table->unsignedInteger('version');
            $table->string('pharmacy_tenant_id');
            $table->json('payload');
            $table->string('signature_hash', 64);
            $table->string('status', 12)->default('sent');
            $table->string('status_note')->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('dispensed_at')->nullable();
            $table->timestamps();
            $table->index(['pharmacy_tenant_id', 'status']);
            $table->index(['issuer_tenant_id', 'consultation_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('hub')->dropIfExists('hub_escripts');
    }
};
