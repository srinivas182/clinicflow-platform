<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

enum InvoiceStatus: string
{
    case Open = 'open';
    case PartPaid = 'part_paid';
    case Paid = 'paid';
    case Void = 'void';
}
