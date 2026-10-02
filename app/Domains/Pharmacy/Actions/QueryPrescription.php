<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Actions;

use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Pharmacist sends the script back to the prescriber with a question.
 * The doctor answers by amending (a new signed version).
 */
class QueryPrescription
{
    public function __construct(private readonly TransitionVisit $transition) {}

    public function handle(Prescription $prescription, Visit $visit, string $question, ?User $by = null): Visit
    {
        if (trim($question) === '') {
            throw ValidationException::withMessages(['question' => 'Write the question for the doctor.']);
        }

        activity('pharmacy')->performedOn($prescription)->causedBy($by)->withProperties(['question' => trim($question)])->log('Script queried');

        return $this->transition->handle($visit, VisitStage::Doctor, $by);
    }
}
