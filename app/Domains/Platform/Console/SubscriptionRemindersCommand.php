<?php

declare(strict_types=1);

namespace App\Domains\Platform\Console;

use App\Domains\Platform\Actions\SendSubscriptionReminders;
use Illuminate\Console\Command;

class SubscriptionRemindersCommand extends Command
{
    protected $signature = 'subscriptions:remind';

    protected $description = 'Email practice owners about trials ending (7, 3, 1 days) and invoices due in 3 days';

    public function handle(SendSubscriptionReminders $reminders): int
    {
        $this->info($reminders->handle().' reminder(s) sent.');

        return self::SUCCESS;
    }
}
