<?php

declare(strict_types=1);

namespace App\Domains\Patients\Enums;

enum IdType: string
{
    case SaId = 'sa_id';
    case Passport = 'passport';
    case Permit = 'permit';
    case None = 'none';
}
