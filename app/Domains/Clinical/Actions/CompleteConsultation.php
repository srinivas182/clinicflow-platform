<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Actions;

use App\Domains\Clinical\Models\Consultation;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Support\DischargeGate;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Ends the consult: needs a primary diagnosis and no unsigned script.
 * The visit moves to Pharmacy when a script was signed, otherwise to Done.
 */
class CompleteConsultation
{
    public function __construct(private readonly TransitionVisit $transition) {}

    public function handle(Consultation $consultation, ?User $by = null): Consultation
    {
        if (! $consultation->diagnoses()->where('is_primary', true)->exists()) {
            throw ValidationException::withMessages(['diagnoses' => 'Add a primary diagnosis before completing the consultation.']);
        }

        if ($consultation->prescriptions()->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages(['prescription' => 'Sign or discard the draft prescription first.']);
        }

        $consultation->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $signed = Prescription::query()->where('consultation_id', $consultation->id)->where('status', 'signed')->exists();
        $visit = $consultation->visit;

        if ($signed) {
            $this->transition->handle($visit, VisitStage::Pharmacy, $by);
        } elseif (DischargeGate::patientDueCents($visit) === 0) {
            $this->transition->handle($visit, VisitStage::Done, $by);
        }
        // Otherwise the patient pays at the front desk, which then discharges the visit.

        activity('clinical')->performedOn($consultation)->causedBy($by)->log('Consultation completed');

        return $consultation;
    }
}
