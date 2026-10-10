<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Website pages already created said "Hosted in AWS Cape Town (af-south-1)", which is not true for
 * every installation (e.g. cPanel hosting in South Africa). Make it "Hosted in South Africa".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('cms_pages')->where('sections', 'like', '%AWS Cape Town (af-south-1)%')->get(['id', 'sections'])
            ->each(fn ($page) => DB::table('cms_pages')->where('id', $page->id)
                ->update(['sections' => str_replace('Hosted in AWS Cape Town (af-south-1)', 'Hosted in South Africa', (string) $page->sections)]));
    }

    public function down(): void {}
};
