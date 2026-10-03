<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform database: provider groups (chains) with group admins and optional
 * combined billing, extra-branch add-ons, practices' own domains, and the
 * calendar apps Clinic Flow is registered with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('billing', 10)->default('separate');
            $table->timestamps();
        });
        Schema::create('provider_group_members', function (Blueprint $table): void {
            $table->foreignId('provider_group_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_id')->unique();
            $table->primary(['provider_group_id', 'tenant_id']);
        });
        Schema::create('provider_group_admins', function (Blueprint $table): void {
            $table->foreignId('provider_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['provider_group_id', 'user_id']);
        });
        Schema::create('group_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_group_id')->constrained();
            $table->string('number', 20)->unique();
            $table->unsignedInteger('total_cents');
            $table->string('status', 8)->default('open');
            $table->timestamp('paid_at')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('group_invoice_id')->nullable()->index();
        });
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('extra_branches')->default(0);
        });

        Schema::create('custom_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('domain')->unique();
            $table->string('token', 40);
            $table->string('status', 10)->default('pending');
            $table->string('ssl_status', 10)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->string('last_check')->nullable();
            $table->timestamps();
        });

        Schema::create('calendar_apps', function (Blueprint $table): void {
            $table->id();
            $table->string('driver', 10)->unique();
            $table->boolean('offered')->default(false);
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_apps');
        Schema::dropIfExists('custom_domains');
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn('extra_branches'));
        Schema::table('subscription_invoices', fn (Blueprint $t) => $t->dropColumn('group_invoice_id'));
        foreach (['group_invoices', 'provider_group_admins', 'provider_group_members', 'provider_groups'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
