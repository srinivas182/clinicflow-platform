<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Platform\Actions\EnforceSubscriptionStatus;
use Illuminate\Console\Command;

class EnforceSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:enforce';

    protected $description = 'Set providers with expired trials or unpaid subscriptions (past grace) to read-only';

    public function handle(EnforceSubscriptionStatus $action): int
    {
        $count = $action->handle();
        $this->info("{$count} provider(s) set to read-only.");

        return self::SUCCESS;
    }
}
