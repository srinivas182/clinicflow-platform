<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

use App\Models\User;

/**
 * Delivers one-time codes (SMS in production; log locally; captured in tests).
 */
interface OtpSender
{
    public function send(User $user, string $code): void;
}
