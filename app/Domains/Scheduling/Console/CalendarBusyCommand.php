<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Console;

use App\Domains\Platform\Models\Provider;
use App\Domains\Scheduling\Calendar\CalendarConnection;
use App\Domains\Scheduling\Calendar\CalendarSync;
use Illuminate\Console\Command;
use Throwable;

class CalendarBusyCommand extends Command
{
    protected $signature = 'calendar:busy';

    protected $description = "Import doctors' busy times from their connected calendars";

    public function handle(CalendarSync $sync): int
    {
        Provider::query()->each(fn (Provider $p) => $p->run(function () use ($sync): void {
            CalendarConnection::query()->whereNotNull('driver')->where('import_busy', true)->each(function (CalendarConnection $c) use ($sync): void {
                try {
                    $sync->importBusy($c);
                } catch (Throwable $e) {
                    $c->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 250)])->save();
                }
            });
        }));

        return self::SUCCESS;
    }
}
