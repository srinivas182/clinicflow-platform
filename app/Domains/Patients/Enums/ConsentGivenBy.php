<?php

declare(strict_types=1);

namespace App\Domains\Patients\Enums;

enum ConsentGivenBy: string
{
    case Patient = 'patient';
    case Guardian = 'guardian';
}
