<?php

declare(strict_types=1);

namespace App\Domains\Locums\Console;

use App\Domains\Locums\Actions\LocumShiftLifecycle;
use Illuminate\Console\Command;

class LocumRemindersCommand extends Command
{
    protected $signature = 'locums:remind';

    protected $description = 'Remind locums and practices about booked shifts in the next 24 hours';

    public function handle(LocumShiftLifecycle $life): int
    {
        $this->info($life->remind().' reminder(s) sent.');

        return self::SUCCESS;
    }
}
