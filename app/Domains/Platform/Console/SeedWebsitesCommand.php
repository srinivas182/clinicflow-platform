<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Support\Website\PlatformWebsite;
use App\Domains\Platform\Support\Website\ProviderWebsite;
use Illuminate\Console\Command;

class SeedWebsitesCommand extends Command
{
    protected $signature = 'websites:seed-defaults';

    protected $description = 'Create any missing default pages for clinicflow.co.za and every provider website (never overwrites edits)';

    public function handle(): int
    {
        $this->info(PlatformWebsite::seed().' platform page(s) created.');
        Provider::query()->each(function (Provider $provider): void {
            $count = $provider->run(fn () => ProviderWebsite::seed($provider->type));
            $this->line("{$provider->name}: {$count} page(s) created");
        });

        return self::SUCCESS;
    }
}
