<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Actions;

use App\Domains\Identity\Models\Staff;
use App\Domains\Scheduling\Enums\SessionType;
use App\Domains\Scheduling\Models\Room;
use App\Domains\Scheduling\Models\RosterSession;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Puts a staff member on the roster. A person cannot be in two sessions at once,
 * and a room cannot hold two sessions at once.
 */
class CreateRosterSession
{
    public function handle(Staff $staff, CarbonInterface $startsAt, CarbonInterface $endsAt, ?Room $room = null, SessionType $type = SessionType::InPerson, int $slotMinutes = 15): RosterSession
    {
        $errors = [];

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $errors['ends_at'] = 'The session must end after it starts.';
        } elseif ($startsAt->diffInHours($endsAt) > 14) {
            $errors['ends_at'] = 'A session cannot be longer than 14 hours.';
        }

        if (! in_array($slotMinutes, [10, 15, 20, 30, 45, 60], true)) {
            $errors['slot_minutes'] = 'Choose a slot length of 10, 15, 20, 30, 45 or 60 minutes.';
        }

        if ($room !== null && ! $room->is_active) {
            $errors['room_id'] = 'This room is not in use.';
        }

        if ($errors === []) {
            $overlap = fn ($q) => $q->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt);

            if (RosterSession::query()->where('staff_id', $staff->id)->where($overlap)->exists()) {
                $errors['starts_at'] = "{$staff->name} is already rostered at this time.";
            }

            if ($room !== null && RosterSession::query()->where('room_id', $room->id)->where($overlap)->exists()) {
                $errors['room_id'] = "{$room->name} is already in use at this time.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $session = RosterSession::create([
            'staff_id' => $staff->id,
            'room_id' => $room?->id,
            'session_type' => $type,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'slot_minutes' => $slotMinutes,
        ]);

        activity('scheduling')->performedOn($session)->withProperties(['staff_id' => $staff->id])->log('Roster session created');

        return $session;
    }
}
