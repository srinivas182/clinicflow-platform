<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform database: the accounting apps the super admin offers (Clinic Flow's
 * registered app credentials per app) and the platform's own books connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_apps', function (Blueprint $table): void {
            $table->id();
            $table->string('driver', 8)->unique();
            $table->boolean('offered')->default(false);
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->string('region', 8)->nullable();
            $table->timestamps();
        });

        Schema::create('platform_accounting_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('driver', 8)->unique();
            $table->boolean('enabled')->default(false);
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('org_id')->nullable();
            $table->string('auto_export', 8)->default('off');
            $table->json('account_map')->nullable();
            $table->date('exported_until')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_accounting_connections');
        Schema::dropIfExists('accounting_apps');
    }
};
