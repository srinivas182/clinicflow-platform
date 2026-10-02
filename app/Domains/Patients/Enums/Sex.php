<?php

declare(strict_types=1);

namespace App\Domains\Patients\Enums;

enum Sex: string
{
    case Female = 'female';
    case Male = 'male';
}
