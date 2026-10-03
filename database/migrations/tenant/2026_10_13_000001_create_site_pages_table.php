<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's own public website on its subdomain or custom domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('title', 160);
            $table->string('meta_description', 300)->nullable();
            $table->json('sections');
            $table->boolean('published')->default(true);
            $table->string('menu_label', 40)->nullable();
            $table->unsignedSmallInteger('menu_order')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_pages');
    }
};
