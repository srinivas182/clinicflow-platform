<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website pages (beta #293, #295, #296): menu positions around the fixed items (Solutions 10,
 * Find Care 20, Pricing 30), redirects from old page addresses, and archived pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('from_slug', 80)->unique();
            $table->string('to_slug', 80);
            $table->timestamps();
        });
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('published');
        });
        // Keep Contact last, as it was before positions were honoured.
        DB::table('cms_pages')->where('slug', 'contact')->where('menu_order', 6)->update(['menu_order' => 40]);
        DB::table('cms_pages')->where('slug', 'contact')->where('menu_label', 'Contact')->update(['menu_label' => 'Contact Us']);
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_redirects');
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
