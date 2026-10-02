<?php

declare(strict_types=1);

namespace App\Domains\Patients\Enums;

enum ConsentType: string
{
    case Popia = 'popia';
    case Treatment = 'treatment';
    case ShareHistory = 'share_history';
}
