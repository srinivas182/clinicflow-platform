<?php

declare(strict_types=1);

namespace App\Domains\Patients\Enums;

enum Channel: string
{
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
}
