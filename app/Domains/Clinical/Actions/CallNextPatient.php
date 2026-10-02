<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Actions;

use App\Domains\Clinical\Enums\TriageColour;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Identity\Models\Staff;
use App\Domains\Scheduling\Models\RosterSession;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Events\VisitStageChanged;
use App\Domains\Visits\Models\Visit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Call next": picks the doctor's next patient, in order:
 *   1. the doctor's own bookings,
 *   2. walk-ins who asked for this doctor,
 *   3. the shared pool, by triage colour then arrival.
 * Rows are locked so two doctors never call the same patient.
 */
class CallNextPatient
{
    public function handle(Staff $doctor): ?Visit
    {
        if ($this->current($doctor) !== null) {
            throw ValidationException::withMessages(['visit' => 'Finish or move your current patient first.']);
        }

        return DB::transaction(function () use ($doctor): ?Visit {
            $waiting = fn (): Builder => Visit::query()
                ->whereDate('visit_date', today())
                ->where('stage', VisitStage::Doctor->value)
                ->whereNull('called_at')
                ->lockForUpdate();

            $visit = $waiting()->where('preferred_staff_id', $doctor->id)->whereNotNull('appointment_id')->orderBy('created_at')->first()
                ?? $waiting()->where('preferred_staff_id', $doctor->id)->orderBy('created_at')->first()
                ?? $waiting()->whereNull('preferred_staff_id')->get()
                    ->sortBy(fn (Visit $v) => [TriageColour::tryFrom((string) $v->triage_colour)?->priority() ?? 9, $v->created_at->getTimestamp()])
                    ->first();

            if (! $visit instanceof Visit) {
                return null;
            }

            $room = RosterSession::query()->where('staff_id', $doctor->id)
                ->where('starts_at', '<=', now())->where('ends_at', '>', now())->value('room_id');

            $visit->forceFill(['doctor_id' => $doctor->id, 'called_at' => now(), 'room_id' => $room])->save();

            activity('visits')->performedOn($visit)->withProperties(['doctor_id' => $doctor->id])->log('Patient called');
            event(VisitStageChanged::fromVisit($visit));

            return $visit;
        });
    }

    /**
     * The patient this doctor has called and not yet moved on.
     */
    public function current(Staff $doctor): ?Visit
    {
        return Visit::query()->whereDate('visit_date', today())
            ->where('stage', VisitStage::Doctor->value)
            ->where('doctor_id', $doctor->id)
            ->whereNotNull('called_at')
            ->whereNotIn('id', Consultation::query()->where('status', 'completed')->select('visit_id'))
            ->first();
    }
}
