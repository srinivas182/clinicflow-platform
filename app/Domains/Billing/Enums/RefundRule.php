<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * Clinic setting: what happens to a prepaid consult fee if the patient leaves
 * before being seen. Shown to the patient before paying.
 */
enum RefundRule: string
{
    case Refund = 'refund';
    case Credit = 'credit';
    case NoRefund = 'none';
}
