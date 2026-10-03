<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Platform\Support\Website\PlatformWebsite;
use Illuminate\Database\Seeder;

/**
 * Default clinicflow.co.za content (menus, pages, images). Safe in production:
 * only missing pages are created.
 */
class PlatformWebsiteSeeder extends Seeder
{
    public function run(): void
    {
        PlatformWebsite::seed();
    }
}
