<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Console;

use App\Domains\Clinical\Actions\ClinicianMessaging;
use App\Domains\Clinical\Care\Prevention;
use App\Domains\Platform\Models\Provider;
use Illuminate\Console\Command;

class CareTickCommand extends Command
{
    protected $signature = 'care:tick {--recalls : also send due recall reminders}';

    protected $description = 'Escalate unanswered urgent clinician messages; optionally send due recall reminders';

    public function handle(): int
    {
        Provider::query()->each(fn (Provider $p) => $p->run(function (): void {
            app(ClinicianMessaging::class)->escalateUrgent();
            if ($this->option('recalls')) {
                app(Prevention::class)->sendDueRecalls();
            }
        }));

        return self::SUCCESS;
    }
}
