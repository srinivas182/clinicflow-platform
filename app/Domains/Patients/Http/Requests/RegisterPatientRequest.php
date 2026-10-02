<?php

declare(strict_types=1);

namespace App\Domains\Patients\Http\Requests;

use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Support\RegistrationData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_names' => ['required', 'string', 'max:120'],
            'surname' => ['required', 'string', 'max:120'],
            'id_type' => ['required', Rule::enum(IdType::class)],
            'id_number' => ['nullable', 'string', 'max:32'],
            'passport_country' => ['nullable', 'string', 'size:2'],
            'date_of_birth' => ['nullable', 'date'],
            'cell' => ['nullable', 'string', 'max:10'],
            'no_cell' => ['boolean'],
            'email' => ['nullable', 'email', 'max:255'],
            'preferred_language' => ['required', Rule::in(['en', 'zu', 'xh', 'af'])],
            'preferred_channel' => ['required', Rule::enum(Channel::class)],
            'address' => ['nullable', 'string', 'max:500'],
            'guardian_name' => ['nullable', 'string', 'max:120'],
            'guardian_relationship' => ['nullable', 'string', 'max:32'],
            'guardian_cell' => ['nullable', 'string', 'max:10'],
            'popia_consent' => ['accepted'],
            'treatment_consent' => ['accepted'],
            'consent_given_by' => ['required', Rule::enum(ConsentGivenBy::class)],
            'maturity_confirmed' => ['boolean'],
            'medical_aid_scheme' => ['nullable', 'string', 'max:120'],
            'medical_aid_plan' => ['nullable', 'string', 'max:120'],
            'medical_aid_number' => ['nullable', 'string', 'max:40'],
            'medical_aid_dependant_code' => ['nullable', 'string', 'max:4'],
        ];
    }

    public function toData(): RegistrationData
    {
        $dob = $this->input('date_of_birth');

        return new RegistrationData(
            firstNames: (string) $this->input('first_names'),
            surname: (string) $this->input('surname'),
            idType: IdType::from((string) $this->input('id_type')),
            idNumber: $this->filled('id_number') ? (string) $this->input('id_number') : null,
            passportCountry: $this->filled('passport_country') ? strtoupper((string) $this->input('passport_country')) : null,
            dateOfBirth: is_string($dob) && $dob !== '' ? CarbonImmutable::parse($dob)->startOfDay() : null,
            cell: $this->filled('cell') ? (string) $this->input('cell') : null,
            noCell: $this->boolean('no_cell'),
            email: $this->filled('email') ? (string) $this->input('email') : null,
            preferredLanguage: (string) $this->input('preferred_language'),
            preferredChannel: Channel::from((string) $this->input('preferred_channel')),
            address: $this->filled('address') ? (string) $this->input('address') : null,
            guardianName: $this->filled('guardian_name') ? (string) $this->input('guardian_name') : null,
            guardianRelationship: $this->filled('guardian_relationship') ? (string) $this->input('guardian_relationship') : null,
            guardianCell: $this->filled('guardian_cell') ? (string) $this->input('guardian_cell') : null,
            popiaConsent: $this->boolean('popia_consent'),
            treatmentConsent: $this->boolean('treatment_consent'),
            consentGivenBy: ConsentGivenBy::from((string) $this->input('consent_given_by')),
            maturityConfirmed: $this->boolean('maturity_confirmed'),
            medicalAidScheme: $this->filled('medical_aid_scheme') ? (string) $this->input('medical_aid_scheme') : null,
            medicalAidPlan: $this->filled('medical_aid_plan') ? (string) $this->input('medical_aid_plan') : null,
            medicalAidNumber: $this->filled('medical_aid_number') ? (string) $this->input('medical_aid_number') : null,
            medicalAidDependantCode: $this->filled('medical_aid_dependant_code') ? (string) $this->input('medical_aid_dependant_code') : null,
        );
    }
}
