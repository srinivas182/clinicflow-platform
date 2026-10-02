<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Identity\Actions\SeedProviderRoles;
use App\Domains\Platform\Models\Provider;
use Illuminate\Console\Command;

class SyncProviderRolesCommand extends Command
{
    protected $signature = 'providers:sync-roles';

    protected $description = 'Add new permissions to every provider\'s role templates after a release';

    public function handle(SeedProviderRoles $action): int
    {
        Provider::query()->each(function (Provider $provider) use ($action): void {
            $action->handle($provider);
            $this->line("Synced {$provider->name}");
        });

        return self::SUCCESS;
    }
}
