<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Support;

use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Telemedicine\Models\TeleAvailability;
use App\Domains\Telemedicine\Models\TeleException;
use App\Domains\Telemedicine\Models\TelePrice;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Free start times for an online consult: within the doctor's hours for that
 * mode (weekly hours, minus days off, plus extra sessions), long enough for
 * the whole duration, clear of other bookings plus the practice buffer.
 */
final class OnlineSlots
{
    /**
     * @return list<string> start times "HH:MM"
     */
    public static function for(int $staffId, string $mode, int $duration, CarbonInterface $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        $windows = self::windows($staffId, $mode, $day);
        if ($windows === []) {
            return [];
        }

        $buffer = TeleSettings::get('buffer_minutes');
        $busy = Appointment::query()->where('staff_id', $staffId)->whereIn('status', AppointmentStatus::occupying())
            ->where(fn ($q) => $q->whereNull('payment_status')->orWhereIn('payment_status', ['paid'])->orWhere(fn ($h) => $h->where('payment_status', 'pending')->where('hold_expires_at', '>', now())))
            ->whereDate('starts_at', $day)->get(['starts_at', 'ends_at']);

        $slots = [];
        foreach ($windows as [$from, $to]) {
            for ($t = $from; $t->addMinutes($duration)->lte($to); $t = $t->addMinutes(15)) {
                $end = $t->addMinutes($duration);
                if ($t->lte(now())) {
                    continue;
                }
                $clash = $busy->contains(fn (Appointment $a) => $t->lt($a->ends_at->copy()->addMinutes($buffer)) && $end->copy()->addMinutes($buffer)->gt($a->starts_at));
                if (! $clash) {
                    $slots[] = $t->format('H:i');
                }
            }
        }

        return array_values(array_unique($slots));
    }

    public static function isAvailable(int $staffId, string $mode, int $duration, CarbonInterface $start): bool
    {
        return in_array(CarbonImmutable::parse($start)->format('H:i'), self::for($staffId, $mode, $duration, $start), true);
    }

    public static function priceCents(int $staffId, string $mode, int $duration): ?int
    {
        $price = TelePrice::query()->where('mode', $mode)->where('duration_minutes', $duration)
            ->where(fn ($q) => $q->where('staff_id', $staffId)->orWhereNull('staff_id'))->orderByRaw('staff_id is null')->first();

        return $price?->price_cents;
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private static function windows(int $staffId, string $mode, CarbonImmutable $day): array
    {
        $exceptions = TeleException::query()->where('staff_id', $staffId)->whereDate('date', $day)->get();
        if ($exceptions->contains(fn (TeleException $e) => $e->type === 'off' && $e->start_time === null)) {
            return [];
        }

        $at = fn (string $time) => $day->setTimeFromTimeString($time);
        $windows = TeleAvailability::query()->where('staff_id', $staffId)->where('weekday', $day->dayOfWeek)
            ->whereIn('mode', [$mode, 'all'])->get()->map(fn (TeleAvailability $a) => [$at($a->start_time), $at($a->end_time)])->all();

        foreach ($exceptions as $e) {
            if ($e->type === 'extra' && $e->start_time !== null && $e->end_time !== null && in_array($e->mode, [$mode, 'all', null], true)) {
                $windows[] = [$at($e->start_time), $at($e->end_time)];
            }
        }

        // Partial days off remove that part of every window.
        foreach ($exceptions->where('type', 'off')->whereNotNull('start_time') as $off) {
            $offFrom = $at((string) $off->start_time);
            $offTo = $at((string) $off->end_time);
            $cut = [];
            foreach ($windows as [$f, $t]) {
                if ($offTo->lte($f) || $offFrom->gte($t)) {
                    $cut[] = [$f, $t];

                    continue;
                }
                if ($offFrom->gt($f)) {
                    $cut[] = [$f, $offFrom];
                }
                if ($offTo->lt($t)) {
                    $cut[] = [$offTo, $t];
                }
            }
            $windows = $cut;
        }

        return array_values($windows);
    }
}
