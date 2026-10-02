<?php

declare(strict_types=1);

namespace App\Domains\Visits\Actions;

use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Domains\Visits\Support\DischargeGate;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Discharge gate: a visit can only be closed as Done when the patient owes
 * nothing, unless an owner or manager overrides with a reason (the caller
 * checks the `discharge.override` permission). Every override is logged.
 */
class DischargeVisit
{
    public function __construct(private readonly TransitionVisit $transition) {}

    public function handle(Visit $visit, ?User $by = null, ?string $overrideReason = null): Visit
    {
        $due = DischargeGate::patientDueCents($visit);

        if ($due > 0) {
            if (blank($overrideReason)) {
                throw ValidationException::withMessages(['balance' => 'R'.number_format($due / 100, 2, '.', ' ').' must be paid before the patient leaves.']);
            }

            $visit->forceFill(['discharge_override_reason' => trim((string) $overrideReason), 'discharge_override_by' => $by?->id])->save();
            activity('billing')->performedOn($visit)->causedBy($by)->withProperties(['due_cents' => $due, 'reason' => $overrideReason])->log('Discharge gate overridden');
        }

        return $this->transition->handle($visit, VisitStage::Done, $by);
    }
}
