<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White-label brands: a partner offers Clinic Flow under its own name, logo,
 * colours and practice domain. Clinic Flow still bills; the partner earns
 * commission through its linked reseller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 40)->unique();
            $table->mediumText('logo')->nullable();
            $table->string('primary_color', 7)->default('#0f7c74');
            $table->string('accent_color', 7)->default('#0b5c57');
            $table->string('support_email')->nullable();
            $table->string('support_phone', 20)->nullable();
            $table->string('footer_text', 300)->nullable();
            $table->boolean('powered_by')->default(true);
            $table->string('practice_domain', 120)->nullable()->unique();
            $table->foreignId('reseller_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->foreignId('brand_id')->nullable()->after('status')->constrained('brands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('brand_id');
        });
        Schema::dropIfExists('brands');
    }
};
