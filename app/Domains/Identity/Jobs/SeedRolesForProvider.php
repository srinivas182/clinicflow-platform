<?php

declare(strict_types=1);

namespace App\Domains\Identity\Jobs;

use App\Domains\Identity\Actions\SeedProviderRoles;
use App\Domains\Platform\Models\Provider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Provisioning step: seed permissions and role templates in a new provider database.
 */
class SeedRolesForProvider implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public TenantWithDatabase $tenant) {}

    public function handle(SeedProviderRoles $action): void
    {
        if ($this->tenant instanceof Provider) {
            $action->handle($this->tenant);
        }
    }
}
