<?php

declare(strict_types=1);

namespace App\Domains\Claims\Actions;

use App\Domains\Claims\Contracts\ClaimsSwitch;
use App\Domains\Claims\Models\EligibilityCheck;
use App\Domains\Patients\Models\Patient;
use App\Domains\Visits\Models\Visit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Real-time medical aid eligibility check (at check-in for medical aid patients).
 */
class CheckEligibility
{
    public function __construct(private readonly ClaimsSwitch $switch) {}

    public function handle(Patient $patient, ?Visit $visit = null, ?User $by = null): EligibilityCheck
    {
        if (blank($patient->medical_aid_scheme) || blank($patient->medical_aid_number)) {
            throw ValidationException::withMessages(['medical_aid' => 'Capture the scheme and member number first.']);
        }

        $result = $this->switch->checkEligibility((string) $patient->medical_aid_scheme, (string) $patient->medical_aid_number, $patient->medical_aid_dependant_code);

        $check = EligibilityCheck::create([
            'patient_id' => $patient->id,
            'visit_id' => $visit?->id,
            'scheme' => (string) $patient->medical_aid_scheme,
            'member_number' => (string) $patient->medical_aid_number,
            'dependant_code' => $patient->medical_aid_dependant_code,
            'status' => $result->status,
            'message' => $result->message,
            'switch' => $this->switch->name(),
            'response' => $result->raw,
            'checked_by' => $by?->id,
            'checked_at' => now(),
        ]);

        activity('claims')->performedOn($patient)->causedBy($by)->withProperties(['status' => $result->status])->log('Eligibility checked');

        return $check;
    }
}
