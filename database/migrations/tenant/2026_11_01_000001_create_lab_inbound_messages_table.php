<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Results received from connected lab systems (HL7 v2 or FHIR). The original
 * message is kept encrypted for the audit trail. Results that cannot be
 * matched to an order wait for staff — a patient is never created automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_inbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('format', 6);
            $table->string('message_id', 100)->nullable();
            $table->longText('raw');
            $table->longText('parsed');
            $table->string('status', 10);
            $table->string('reason')->nullable();
            $table->foreignUlid('lab_order_id')->nullable()->constrained();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['api_key_id', 'message_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_inbound_messages');
    }
};
