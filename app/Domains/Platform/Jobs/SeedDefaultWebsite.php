<?php

declare(strict_types=1);

namespace App\Domains\Platform\Jobs;

use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Support\Website\ProviderWebsite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Provisioning step: give the new provider its default website.
 */
class SeedDefaultWebsite implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public TenantWithDatabase $tenant) {}

    public function handle(): void
    {
        if ($this->tenant instanceof Provider) {
            $provider = $this->tenant;
            $provider->run(fn () => ProviderWebsite::seed($provider->type));
        }
    }
}
