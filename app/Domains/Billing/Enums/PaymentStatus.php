<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
