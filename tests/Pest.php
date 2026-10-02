<?php

use App\Domains\Patients\Support\SaIdNumber;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use Tests\TestCase;

/*
 * All feature, unit and architecture tests run on the Laravel TestCase.
 */
pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Arch');

/**
 * Build a valid South African ID number (Luhn check digit computed).
 */
function saId(string $dob = '880412', string $sequence = '0547', string $citizenship = '0'): string
{
    $partial = $dob.$sequence.$citizenship.'8';

    for ($check = 0; $check <= 9; $check++) {
        if (SaIdNumber::luhnValid($partial.$check)) {
            return $partial.$check;
        }
    }

    throw new RuntimeException('Could not build an ID number.');
}

function makeProvider(string $name, ProviderType $type = ProviderType::Clinic, ?string $domain = null): Provider
{
    $provider = Provider::create(['name' => $name, 'type' => $type, 'status' => ProviderStatus::Trial]);

    if ($domain !== null) {
        $provider->domains()->create(['domain' => $domain]);
    }

    return $provider;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function patientPayload(array $overrides = []): array
{
    return array_merge([
        'first_names' => 'Thandi',
        'surname' => 'Mokoena',
        'id_type' => 'sa_id',
        'id_number' => saId(),
        'cell' => '0825550147',
        'no_cell' => false,
        'preferred_language' => 'zu',
        'preferred_channel' => 'whatsapp',
        'popia_consent' => true,
        'treatment_consent' => true,
        'consent_given_by' => 'patient',
        'maturity_confirmed' => false,
        'medical_aid_scheme' => 'Discovery Health',
        'medical_aid_number' => 'DH412778901',
    ], $overrides);
}
