<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

/**
 * The kinds of business that can subscribe to Dr Business Flow.
 * Each provider gets its own isolated database.
 */
enum ProviderType: string
{
    case Clinic = 'clinic';
    case IndependentDoctor = 'independent_doctor';
    case Pharmacy = 'pharmacy';
    case Lab = 'lab';

    public function label(): string
    {
        return match ($this) {
            self::Clinic => 'Clinic',
            self::IndependentDoctor => 'Independent doctor',
            self::Pharmacy => 'Pharmacy',
            self::Lab => 'Lab',
        };
    }

    /**
     * Only clinics and independent doctors may run telemedicine.
     */
    public function canOfferTelemedicine(): bool
    {
        return in_array($this, [self::Clinic, self::IndependentDoctor], true);
    }
}
