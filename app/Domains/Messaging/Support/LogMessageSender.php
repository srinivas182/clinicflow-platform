<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Support;

use App\Domains\Messaging\Contracts\MessageSender;
use Illuminate\Support\Facades\Log;

class LogMessageSender implements MessageSender
{
    /** @var list<array{channel: string, recipient: string, body: string}> */
    public array $sent = [];

    public function send(string $channel, string $recipient, ?string $subject, string $body): bool
    {
        $this->sent[] = ['channel' => $channel, 'recipient' => $recipient, 'body' => $body];
        Log::info('Message sent', ['channel' => $channel, 'recipient' => $recipient]);

        return true;
    }
}
