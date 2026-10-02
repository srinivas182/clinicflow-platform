<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

enum LineKind: string
{
    case Consultation = 'consultation';
    case Procedure = 'procedure';
    case Medicine = 'medicine';
    case Certificate = 'certificate';
    case Other = 'other';
}
