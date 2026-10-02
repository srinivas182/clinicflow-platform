<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Billing\Actions\CollectSubscriptionDebits;
use Illuminate\Console\Command;

class CollectSubscriptionDebitsCommand extends Command
{
    protected $signature = 'subscriptions:collect';

    protected $description = 'Charge saved cards for subscription invoices that are due (Paystack, Peach; PayFast charges itself)';

    public function handle(CollectSubscriptionDebits $action): int
    {
        $result = $action->handle();
        $this->info("{$result['paid']} collected, {$result['failed']} failed.");

        return self::SUCCESS;
    }
}
