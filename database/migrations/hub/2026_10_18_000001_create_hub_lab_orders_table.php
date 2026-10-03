<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lab requests between practices and network labs. Results pass through in
 * result_payload only until delivered to the requesting practice, then are erased.
 */
return new class extends Migration
{
    protected $connection = 'hub';

    public function up(): void
    {
        Schema::connection('hub')->create('hub_lab_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('identity_id')->constrained('hub_identities');
            $table->string('issuer_tenant_id');
            $table->string('issuer_order_id', 26);
            $table->string('lab_tenant_id');
            $table->string('lab_order_id', 26)->nullable();
            $table->json('payload');
            $table->string('status', 12)->default('sent');
            $table->string('status_note')->nullable();
            $table->longText('result_payload')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['lab_tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('hub')->dropIfExists('hub_lab_orders');
    }
};
