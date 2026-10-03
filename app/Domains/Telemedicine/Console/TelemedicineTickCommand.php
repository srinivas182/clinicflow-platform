<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Console;

use App\Domains\Platform\Models\Provider;
use App\Domains\Telemedicine\Actions\OnlineBooking;
use App\Domains\Telemedicine\Actions\TrackTeleSession;
use App\Domains\Telemedicine\Models\RefundTask;
use App\Domains\Telemedicine\Support\Telemedicine;
use Illuminate\Console\Command;

/**
 * Every minute, for every provider with telemedicine: expire unpaid holds,
 * refund doctor no-shows and close consults whose time is over.
 */
class TelemedicineTickCommand extends Command
{
    protected $signature = 'telemedicine:tick';

    protected $description = 'Expire unpaid online-consult holds, refund doctor no-shows, close finished consults';

    public function handle(): int
    {
        Provider::query()->each(function (Provider $provider): void {
            if (! Telemedicine::enabledFor($provider->id)) {
                return;
            }
            $provider->run(function (): void {
                $booking = app(OnlineBooking::class);
                $booking->expireHolds();
                $booking->refundDoctorNoShows();
                $booking->closeFinished(app(TrackTeleSession::class));
                RefundTask::query()->whereNull('done_at')->where('due_at', '<', now())->increment('reminders_sent');
            });
        });

        return self::SUCCESS;
    }
}
