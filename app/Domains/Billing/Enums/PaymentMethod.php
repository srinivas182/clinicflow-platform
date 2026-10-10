<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * How a patient paid the provider. Money always goes to the provider's own
 * account; Dr Business Flow never holds patient money (ADR 0009).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case CardMachine = 'card_machine';
    case Eft = 'eft';
    case PayLink = 'pay_link';
    case MedicalAid = 'medical_aid';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::CardMachine => 'Card machine',
            self::Eft => 'EFT',
            self::PayLink => 'Pay link',
            self::MedicalAid => 'Medical aid',
        };
    }

    public function needsReference(): bool
    {
        return in_array($this, [self::CardMachine, self::Eft], true);
    }
}
