<?php

declare(strict_types=1);

namespace App\Domains\Visits\Enums;

enum PayerType: string
{
    case Cash = 'cash';
    case MedicalAid = 'medical_aid';
}
