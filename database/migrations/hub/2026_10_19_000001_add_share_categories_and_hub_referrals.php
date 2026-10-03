<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Share-history consent with categories and expiry; referrals between practices.
 */
return new class extends Migration
{
    protected $connection = 'hub';

    public function up(): void
    {
        Schema::connection('hub')->table('hub_consents', function (Blueprint $table): void {
            $table->json('categories')->nullable();
            $table->timestamp('expires_at')->nullable();
        });

        Schema::connection('hub')->create('hub_referrals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('identity_id')->constrained('hub_identities');
            $table->string('from_tenant_id');
            $table->string('from_referral_id', 26);
            $table->string('to_tenant_id');
            $table->string('to_referral_id', 26)->nullable();
            $table->string('status', 12)->default('sent');
            $table->timestamps();
            $table->index(['to_tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('hub')->dropIfExists('hub_referrals');
        Schema::connection('hub')->table('hub_consents', fn (Blueprint $t) => $t->dropColumn(['categories', 'expires_at']));
    }
};
