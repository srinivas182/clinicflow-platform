<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * Clinic setting: when cash patients pay the consultation fee.
 */
enum PaymentTiming: string
{
    case AtCheckIn = 'check_in';
    case AtEnd = 'end';
}
