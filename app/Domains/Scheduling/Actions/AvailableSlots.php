<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Actions;

use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Scheduling\Models\RosterSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Free slots for a doctor on a day, from their roster sessions minus booked appointments.
 */
class AvailableSlots
{
    /**
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, session_id: int}>
     */
    public function handle(int $staffId, CarbonInterface $day): array
    {
        $dayStart = CarbonImmutable::parse($day)->startOfDay();
        $dayEnd = $dayStart->endOfDay();
        $now = CarbonImmutable::now();

        $sessions = RosterSession::query()
            ->where('staff_id', $staffId)
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->orderBy('starts_at')
            ->get();

        $taken = Appointment::query()
            ->where('staff_id', $staffId)
            ->whereIn('status', AppointmentStatus::occupying())
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->get(['starts_at', 'ends_at']);

        $slots = [];

        foreach ($sessions as $session) {
            $cursor = CarbonImmutable::parse($session->starts_at);
            $end = CarbonImmutable::parse($session->ends_at);

            while ($cursor->addMinutes($session->slot_minutes)->lessThanOrEqualTo($end)) {
                $slotEnd = $cursor->addMinutes($session->slot_minutes);
                $clash = $taken->contains(fn (Appointment $a) => $a->starts_at->lt($slotEnd) && $a->ends_at->gt($cursor));

                if (! $clash && $cursor->greaterThan($now)) {
                    $slots[] = ['starts_at' => $cursor, 'ends_at' => $slotEnd, 'session_id' => $session->id];
                }

                $cursor = $slotEnd;
            }
        }

        return $slots;
    }
}
