<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Enums;

enum AppointmentStatus: string
{
    case Booked = 'booked';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * Statuses that still occupy the doctor's time.
     *
     * @return list<string>
     */
    public static function occupying(): array
    {
        return [self::Booked->value, self::CheckedIn->value, self::Completed->value];
    }
}
