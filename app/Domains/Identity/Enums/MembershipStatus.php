<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
