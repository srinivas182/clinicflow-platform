<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Models\Provider;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the permission catalogue and role templates inside a provider database.
 * Runs during provider provisioning (TenancyServiceProvider).
 */
class SeedProviderRoles
{
    public function handle(Provider $provider): void
    {
        $provider->run(function () use ($provider): void {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            foreach (Permission::all() as $name) {
                PermissionModel::findOrCreate($name, 'web');
            }

            foreach (StaffRole::forProviderType($provider->type) as $role) {
                Role::findOrCreate($role->value, 'web')->syncPermissions($role->defaultPermissions());
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
}
