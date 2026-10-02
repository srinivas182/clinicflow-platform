<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Support;

/**
 * Repeat limits by medicine schedule (Medicines Act). S6: no repeats.
 * S3 and S4: at most five repeats. Other schedules are not limited here.
 * To be confirmed by the pharmacy reviewer before go-live.
 */
final class ScheduleRules
{
    public static function maxRepeats(string $schedule): ?int
    {
        return match (strtoupper($schedule)) {
            'S6' => 0,
            'S3', 'S4' => 5,
            default => null,
        };
    }
}
