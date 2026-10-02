<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Contracts;

/**
 * Delivers email or SMS. Log driver locally; SMS gateway and mailer in production.
 */
interface MessageSender
{
    public function send(string $channel, string $recipient, ?string $subject, string $body): bool;
}
