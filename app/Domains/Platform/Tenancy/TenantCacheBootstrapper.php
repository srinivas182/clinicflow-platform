<?php

declare(strict_types=1);

namespace App\Domains\Platform\Tenancy;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Keeps each practice's cache separate on any cache store. Stores that support tags (Redis, array)
 * use the tenancy package's tag-based bootstrapper unchanged; stores that do not (database, file —
 * shared cPanel hosting) get a per-practice key prefix instead.
 */
class TenantCacheBootstrapper implements TenancyBootstrapper
{
    private bool $usingTags = false;

    private ?string $centralPrefix = null;

    public function __construct(private readonly CacheTenancyBootstrapper $tags) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->usingTags = Cache::store()->getStore() instanceof TaggableStore;
        if ($this->usingTags) {
            $this->tags->bootstrap($tenant);

            return;
        }
        $this->centralPrefix ??= (string) config('cache.prefix');
        $this->usePrefix($this->centralPrefix.'tenant_'.$tenant->getTenantKey().'_');
    }

    public function revert(): void
    {
        if ($this->usingTags) {
            $this->tags->revert();

            return;
        }
        if ($this->centralPrefix !== null) {
            $this->usePrefix($this->centralPrefix);
        }
    }

    private function usePrefix(string $prefix): void
    {
        config(['cache.prefix' => $prefix]);
        /** @var CacheManager $manager */
        $manager = app('cache');
        // Stores are rebuilt with the new prefix the next time they are used.
        foreach (array_keys((array) config('cache.stores')) as $store) {
            $manager->forgetDriver((string) $store);
        }
    }
}
