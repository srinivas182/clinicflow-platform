<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Network Hub (hub connection): cross-provider patient identity, links to each
 * provider's local record, consents and link requests. No clinical data.
 */
return new class extends Migration
{
    protected $connection = 'hub';

    public function up(): void
    {
        Schema::connection('hub')->create('hub_identities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('cell', 10)->unique();
            $table->string('sa_id_hash', 64)->nullable()->unique();
            $table->string('first_names');
            $table->string('surname');
            $table->date('date_of_birth');
            $table->timestamps();
        });

        Schema::connection('hub')->create('hub_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('identity_id')->constrained('hub_identities')->cascadeOnDelete();
            $table->string('tenant_id');
            $table->string('patient_id', 26);
            $table->string('status', 12)->default('active');
            $table->timestamp('linked_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unique(['tenant_id', 'patient_id']);
            $table->index(['identity_id', 'status']);
        });

        Schema::connection('hub')->create('hub_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('identity_id')->constrained('hub_identities')->cascadeOnDelete();
            $table->string('tenant_id');
            $table->string('scope', 24);
            $table->string('captured_via', 16);
            $table->timestamp('granted_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->index(['identity_id', 'tenant_id', 'scope']);
        });

        Schema::connection('hub')->create('hub_link_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('identity_id')->constrained('hub_identities')->cascadeOnDelete();
            $table->string('tenant_id');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['hub_link_requests', 'hub_consents', 'hub_links', 'hub_identities'] as $t) {
            Schema::connection('hub')->dropIfExists($t);
        }
    }
};
