<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock and price that opted-in network pharmacies publish (no patient data).
 */
return new class extends Migration
{
    protected $connection = 'hub';

    public function up(): void
    {
        Schema::connection('hub')->create('hub_pharmacy_stock', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('nappi_code', 20);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('price_cents');
            $table->timestamp('published_at');
            $table->unique(['tenant_id', 'nappi_code']);
        });
    }

    public function down(): void
    {
        Schema::connection('hub')->dropIfExists('hub_pharmacy_stock');
    }
};
