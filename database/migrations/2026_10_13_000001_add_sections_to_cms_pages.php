<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * clinicflow.co.za pages built from editable sections, with menu placement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->json('sections')->nullable()->after('body');
            $table->string('menu_label', 40)->nullable()->after('sections');
            $table->unsignedSmallInteger('menu_order')->nullable()->after('menu_label');
        });
    }

    public function down(): void
    {
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->dropColumn(['sections', 'menu_label', 'menu_order']);
        });
    }
};
