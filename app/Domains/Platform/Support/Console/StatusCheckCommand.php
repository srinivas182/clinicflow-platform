<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support\Console;

use App\Domains\Platform\Support\StatusPage;
use Illuminate\Console\Command;

class StatusCheckCommand extends Command
{
    protected $signature = 'status:check';

    protected $description = 'Check platform components for the status page';

    public function handle(StatusPage $status): int
    {
        foreach ($status->check() as $key => $state) {
            $this->line("{$key}: {$state}");
        }

        return self::SUCCESS;
    }
}
