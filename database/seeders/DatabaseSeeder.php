<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Platform seed: packages and a platform admin for local and test environments.
     */
    public function run(): void
    {
        $this->call(PackageSeeder::class);
        $this->call(PlatformWebsiteSeeder::class);

        if (! app()->isProduction()) {
            $this->call(ClinicalReferenceSeeder::class);
        }

        if (! app()->isProduction()) {
            User::query()->firstOrNew(['email' => 'admin@clinicflow.test'])
                ->forceFill(['name' => 'Platform Admin', 'phone' => '0800000001', 'password' => 'password', 'is_platform_admin' => true])
                ->save();
        }
    }
}
