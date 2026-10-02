<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Actions;

use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\MessagingUsage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sends an email or SMS for the current provider, logs it in the provider
 * database and counts it against the provider's monthly allowance.
 */
class SendMessage
{
    public function __construct(private readonly MessageSender $sender) {}

    public function handle(string $channel, string $recipient, string $body, ?string $subject = null, ?string $relatedType = null, ?string $relatedId = null): bool
    {
        if (! in_array($channel, ['sms', 'email'], true)) {
            throw new InvalidArgumentException("Unknown channel {$channel}.");
        }

        $ok = $this->sender->send($channel, $recipient, $subject, $body);
        $units = MessagingUsage::units($channel, $body);

        DB::table('message_log')->insert([
            'channel' => $channel, 'recipient' => $recipient, 'subject' => $subject, 'body' => $body,
            'status' => $ok ? 'sent' : 'failed', 'units' => $units, 'related_type' => $relatedType, 'related_id' => $relatedId, 'sent_at' => now(),
        ]);

        $tenant = tenant();
        if ($ok && $tenant !== null) {
            MessagingUsage::record((string) $tenant->getTenantKey(), $units);
        }

        return $ok;
    }
}
