<?php

declare(strict_types=1);

namespace App\Domains\Patients\Actions;

use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Enums\ConsentType;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Support\RegistrationData;
use App\Domains\Patients\Support\SaIdNumber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers a patient with the current provider, applying the registration rules
 * from the Clinic Flow logic flow:
 *  - SA ID must pass the check digit; it fills date of birth and sex.
 *  - Under 12: guardian name, relationship and cell are required; guardian consents.
 *  - 12 to 17: guardian consents, or the patient consents with maturity confirmed.
 *  - 18 and over: the patient consents.
 *  - A 10-digit cell number, or "no cellphone" ticked.
 *  - POPIA and treatment consent are both required.
 *  - The same SA ID cannot be registered twice with one provider.
 */
class RegisterPatient
{
    public function handle(RegistrationData $data, ?User $actor = null): Patient
    {
        $errors = [];
        $sa = null;
        $dob = $data->dateOfBirth;
        $sex = null;

        if ($data->idType === IdType::SaId) {
            $sa = $data->idNumber !== null ? SaIdNumber::tryParse($data->idNumber) : null;

            if ($sa === null) {
                $errors['id_number'] = 'This SA ID number is not valid. Check the 13 digits.';
            } else {
                $dob = $sa->dateOfBirth();
                $sex = $sa->sex();

                if (Patient::query()->where('id_number_hash', $sa->lookupHash())->exists()) {
                    $errors['id_number'] = 'A patient with this SA ID number is already registered. Search first.';
                }
            }
        } elseif (in_array($data->idType, [IdType::Passport, IdType::Permit], true)) {
            if ($data->idNumber === null || $data->idNumber === '') {
                $errors['id_number'] = 'Enter the passport or permit number.';
            }
            if ($data->passportCountry === null) {
                $errors['passport_country'] = 'Choose the issuing country.';
            }
        }

        if ($dob === null) {
            $errors['date_of_birth'] = 'Date of birth is required.';
        } elseif ($dob->isFuture()) {
            $errors['date_of_birth'] = 'Date of birth cannot be in the future.';
        }

        if (! $data->noCell && ($data->cell === null || ! preg_match('/^0\d{9}$/', $data->cell))) {
            $errors['cell'] = 'Enter a 10-digit cell number starting with 0, or tick "No cellphone".';
        }

        if (! $data->popiaConsent) {
            $errors['popia_consent'] = 'POPIA consent is required.';
        }
        if (! $data->treatmentConsent) {
            $errors['treatment_consent'] = 'Treatment consent is required.';
        }

        $age = $dob !== null && ! $dob->isFuture() ? (int) $dob->diffInYears(CarbonImmutable::today()) : null;

        if ($age !== null && $age < 12) {
            if (blank($data->guardianName) || blank($data->guardianRelationship) || ! preg_match('/^0\d{9}$/', (string) $data->guardianCell)) {
                $errors['guardian_name'] = 'Children under 12 need a guardian name, relationship and 10-digit cell number.';
            }
            if ($data->consentGivenBy !== ConsentGivenBy::Guardian) {
                $errors['consent_given_by'] = 'For children under 12 the guardian gives consent.';
            }
        } elseif ($age !== null && $age < 18) {
            if ($data->consentGivenBy === ConsentGivenBy::Patient && ! $data->maturityConfirmed) {
                $errors['maturity_confirmed'] = 'Confirm the patient is mature enough to consent, or record guardian consent.';
            }
            if ($data->consentGivenBy === ConsentGivenBy::Guardian && blank($data->guardianName)) {
                $errors['guardian_name'] = 'Enter the guardian who is giving consent.';
            }
        } elseif ($age !== null && $data->consentGivenBy !== ConsentGivenBy::Patient) {
            $errors['consent_given_by'] = 'Adult patients give their own consent.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($data, $sa, $dob, $sex, $actor): Patient {
            $patient = Patient::create([
                'first_names' => trim($data->firstNames),
                'surname' => trim($data->surname),
                'id_type' => $data->idType,
                'id_number' => $sa?->value() ?? $data->idNumber,
                'id_number_hash' => $sa?->lookupHash(),
                'passport_country' => $data->passportCountry,
                'date_of_birth' => $dob,
                'sex' => $sex,
                'cell' => $data->noCell ? null : $data->cell,
                'no_cell' => $data->noCell,
                'email' => $data->email,
                'preferred_language' => $data->preferredLanguage,
                'preferred_channel' => $data->preferredChannel,
                'address' => $data->address,
                'guardian_name' => $data->guardianName,
                'guardian_relationship' => $data->guardianRelationship,
                'guardian_cell' => $data->guardianCell,
                'medical_aid_scheme' => $data->medicalAidScheme,
                'medical_aid_plan' => $data->medicalAidPlan,
                'medical_aid_number' => $data->medicalAidNumber,
                'medical_aid_dependant_code' => $data->medicalAidDependantCode,
                'registered_by' => $actor?->id,
            ]);

            foreach ([ConsentType::Popia, ConsentType::Treatment] as $type) {
                $patient->consents()->create([
                    'type' => $type,
                    'given_by' => $data->consentGivenBy,
                    'maturity_confirmed' => $data->maturityConfirmed,
                    'granted_at' => now(),
                    'captured_by' => $actor?->id,
                ]);
            }

            activity('patients')->performedOn($patient)->causedBy($actor)->log('Patient registered');

            return $patient;
        });
    }
}
