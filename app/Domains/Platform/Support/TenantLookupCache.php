<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

use App\Domains\Platform\Models\Provider;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

/**
 * The practice lookup by domain is cached. Clearing must run in the platform context: inside a
 * practice, the cache is scoped to that practice and would miss the platform's cached lookup.
 */
final class TenantLookupCache
{
    public static function forget(Provider|string $provider): void
    {
        $tenant = $provider instanceof Provider ? $provider : Provider::query()->whereKey($provider)->first();
        if (! $tenant instanceof Provider) {
            return;
        }
        tenancy()->central(fn () => app(DomainTenantResolver::class)->invalidateCache($tenant));
    }
}
