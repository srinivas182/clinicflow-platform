<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Actions;

use App\Domains\Identity\Models\Staff;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * First doctor to accept a red-triage patient takes them.
 */
class AcceptRedAlert
{
    public function handle(Visit $visit, Staff $doctor): Visit
    {
        return DB::transaction(function () use ($visit, $doctor): Visit {
            $locked = Visit::query()->lockForUpdate()->findOrFail($visit->id);

            if ($locked->triage_colour !== 'red' || $locked->stage !== VisitStage::Doctor) {
                throw ValidationException::withMessages(['visit' => 'This is not a waiting red-triage patient.']);
            }

            if ($locked->alert_accepted_at !== null) {
                throw ValidationException::withMessages(['visit' => 'Another doctor has already accepted this patient.']);
            }

            $locked->forceFill(['doctor_id' => $doctor->id, 'called_at' => now(), 'alert_accepted_at' => now()])->save();
            activity('clinical')->performedOn($locked)->withProperties(['doctor_id' => $doctor->id])->log('Red triage accepted');

            return $locked;
        });
    }
}
