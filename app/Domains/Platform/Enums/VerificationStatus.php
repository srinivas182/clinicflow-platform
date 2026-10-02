<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

enum VerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
