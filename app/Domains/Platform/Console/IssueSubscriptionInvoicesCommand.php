<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Platform\Actions\IssueSubscriptionInvoices;
use Illuminate\Console\Command;

class IssueSubscriptionInvoicesCommand extends Command
{
    protected $signature = 'subscriptions:invoice';

    protected $description = 'Issue subscription invoices due in the next 3 days';

    public function handle(IssueSubscriptionInvoices $action): int
    {
        $this->info($action->handle().' invoice(s) issued.');

        return self::SUCCESS;
    }
}
