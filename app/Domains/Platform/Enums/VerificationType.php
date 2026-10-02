<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

/**
 * Registrations checked before a provider is listed or can issue scripts.
 */
enum VerificationType: string
{
    case BhfPracticeNumber = 'bhf_practice_number';
    case OwnerHpcsa = 'owner_hpcsa';
    case DispensingLicence = 'dispensing_licence';
    case SapcRegistration = 'sapc_registration';
    case SanasAccreditation = 'sanas_accreditation';
    case CompanyRegistration = 'company_registration';
    case PopiaAgreement = 'popia_agreement';

    public function label(): string
    {
        return match ($this) {
            self::BhfPracticeNumber => 'BHF practice number',
            self::OwnerHpcsa => 'Owner HPCSA registration',
            self::DispensingLicence => 'Dispensing licence',
            self::SapcRegistration => 'SAPC pharmacy registration',
            self::SanasAccreditation => 'SANAS accreditation',
            self::CompanyRegistration => 'Company registration',
            self::PopiaAgreement => 'POPIA operator agreement',
        };
    }

    /**
     * Checks required before a provider of this type can go live.
     *
     * @return list<self>
     */
    public static function requiredFor(ProviderType $type): array
    {
        return match ($type) {
            ProviderType::Clinic => [self::BhfPracticeNumber, self::OwnerHpcsa, self::CompanyRegistration, self::PopiaAgreement],
            ProviderType::IndependentDoctor => [self::BhfPracticeNumber, self::OwnerHpcsa, self::PopiaAgreement],
            ProviderType::Pharmacy => [self::SapcRegistration, self::CompanyRegistration, self::PopiaAgreement],
            ProviderType::Lab => [self::SanasAccreditation, self::CompanyRegistration, self::PopiaAgreement],
        };
    }
}
