<?php

declare(strict_types=1);

namespace App\Domains\Patients\Actions;

use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Enums\ConsentType;
use App\Domains\Patients\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records POPIA and treatment consent for a patient who has none on file
 * (imported from a legacy system). Required before their first check-in.
 */
class CaptureConsent
{
    public function handle(Patient $patient, ConsentGivenBy $givenBy, bool $popia, bool $treatment, bool $maturityConfirmed = false, ?User $by = null): Patient
    {
        if (! $popia || ! $treatment) {
            throw ValidationException::withMessages(['consent' => 'Both POPIA and treatment consent are needed.']);
        }

        $age = $patient->ageInYears();
        if ($age < 12 && $givenBy !== ConsentGivenBy::Guardian) {
            throw ValidationException::withMessages(['consent_given_by' => 'For children under 12 the guardian gives consent.']);
        }
        if ($age >= 12 && $age < 18 && $givenBy === ConsentGivenBy::Patient && ! $maturityConfirmed) {
            throw ValidationException::withMessages(['maturity_confirmed' => 'Confirm the patient is mature enough to consent, or record guardian consent.']);
        }

        return DB::transaction(function () use ($patient, $givenBy, $maturityConfirmed, $by): Patient {
            foreach ([ConsentType::Popia, ConsentType::Treatment] as $type) {
                $patient->consents()->create(['type' => $type, 'given_by' => $givenBy, 'maturity_confirmed' => $maturityConfirmed, 'granted_at' => now(), 'captured_by' => $by?->id]);
            }
            $patient->forceFill(['needs_consent' => false])->save();
            activity('patients')->performedOn($patient)->causedBy($by)->log('Consent captured');

            return $patient;
        });
    }
}
