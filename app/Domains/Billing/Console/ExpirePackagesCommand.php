<?php

declare(strict_types=1);

namespace App\Domains\Billing\Console;

use App\Domains\Billing\Prepaid\PrepaidPackages;
use App\Domains\Platform\Models\Provider;
use Illuminate\Console\Command;

class ExpirePackagesCommand extends Command
{
    protected $signature = 'packages:expire';

    protected $description = 'Expire prepaid packages older than their validity period';

    public function handle(): int
    {
        Provider::query()->each(fn (Provider $p) => $p->run(fn () => app(PrepaidPackages::class)->expire()));

        return self::SUCCESS;
    }
}
