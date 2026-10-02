<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

enum GatewayMode: string
{
    case Test = 'test';
    case Live = 'live';
}
