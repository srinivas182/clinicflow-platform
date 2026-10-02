<?php

declare(strict_types=1);

namespace App\Domains\Patients\Support;

use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Enums\IdType;
use Carbon\CarbonImmutable;

/**
 * Everything reception captures to register a patient.
 */
final readonly class RegistrationData
{
    public function __construct(
        public string $firstNames,
        public string $surname,
        public IdType $idType,
        public ?string $idNumber,
        public ?string $passportCountry,
        public ?CarbonImmutable $dateOfBirth,
        public ?string $cell,
        public bool $noCell,
        public ?string $email,
        public string $preferredLanguage,
        public Channel $preferredChannel,
        public ?string $address,
        public ?string $guardianName,
        public ?string $guardianRelationship,
        public ?string $guardianCell,
        public bool $popiaConsent,
        public bool $treatmentConsent,
        public ConsentGivenBy $consentGivenBy,
        public bool $maturityConfirmed,
        public ?string $medicalAidScheme = null,
        public ?string $medicalAidPlan = null,
        public ?string $medicalAidNumber = null,
        public ?string $medicalAidDependantCode = null,
    ) {}
}
