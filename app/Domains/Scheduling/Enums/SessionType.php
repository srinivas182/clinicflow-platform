<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Enums;

enum SessionType: string
{
    case InPerson = 'in_person';
    case Telemedicine = 'telemedicine';
}
