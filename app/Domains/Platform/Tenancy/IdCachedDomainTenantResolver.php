<?php

declare(strict_types=1);

namespace App\Domains\Platform\Tenancy;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

/**
 * Practice lookup by domain, cached as the practice's ID only (plain text). Caching the whole
 * practice object breaks on cache stores that serialise values (database, file, Redis), because
 * the app refuses to rebuild cached objects (cache.serializable_classes = false, a security
 * default). Anything other than an ID found in the cache is treated as a miss and replaced.
 */
class IdCachedDomainTenantResolver extends DomainTenantResolver
{
    /**
     * @param  mixed  ...$args
     */
    public function resolve(...$args): Tenant
    {
        if (! static::$shouldCache) {
            return $this->resolveWithoutCache(...$args);
        }
        $key = $this->getCacheKey(...$args);
        $id = $this->cache->get($key);
        if (is_string($id) || is_int($id)) {
            $tenant = tenancy()->find($id);
            if ($tenant instanceof Tenant) {
                $this->resolved($tenant, ...$args);

                return $tenant;
            }
        }
        $tenant = $this->resolveWithoutCache(...$args);
        $this->cache->put($key, $tenant->getTenantKey(), static::$cacheTTL);

        return $tenant;
    }
}
