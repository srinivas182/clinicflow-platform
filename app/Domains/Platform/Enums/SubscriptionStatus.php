<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case ReadOnly = 'read_only';
    case Cancelled = 'cancelled';
}
