<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Support;

use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Gateways\EmailGateway;
use App\Domains\Messaging\Gateways\SmsGateway;
use App\Domains\Messaging\Models\MessagingProvider;
use Illuminate\Support\Facades\Log;

/**
 * Routes every message through the super admin's default supplier for the
 * channel. Test mode delivers only to the listed test recipients; everything
 * else is recorded as suppressed. With no supplier, messages are logged.
 */
class GatewayMessageSender implements MessageSender
{
    /** @var list<array{channel: string, recipient: string, body: string, status: string}> */
    public array $sent = [];

    public string $fromName = 'Clinic Flow';

    public ?string $replyTo = null;

    public function send(string $channel, string $recipient, ?string $subject, string $body): bool
    {
        $provider = MessagingProvider::activeFor($channel);
        $status = 'logged';
        $ok = true;

        if ($provider instanceof MessagingProvider) {
            $testRecipients = array_map(fn ($r) => $channel === 'sms' ? SmsGateway::e164((string) $r) : strtolower(trim((string) $r)), $provider->test_recipients ?? []);
            $target = $channel === 'sms' ? SmsGateway::e164($recipient) : strtolower(trim($recipient));

            if ($provider->isTestMode() && ! in_array($target, $testRecipients, true)) {
                $status = 'suppressed';
            } else {
                $result = $channel === 'sms'
                    ? SmsGateway::send($provider, $recipient, $body)
                    : EmailGateway::send($provider, $recipient, (string) ($subject ?? $this->fromName), $body, $this->fromName, $this->replyTo);
                $ok = $result['ok'];
                $status = $ok ? 'sent' : 'failed';
                if (! $ok) {
                    Log::warning('Message delivery failed', ['driver' => $provider->driver->value, 'error' => $result['error']]);
                }
            }
        } else {
            Log::info('Message logged (no supplier configured)', ['channel' => $channel, 'recipient' => $recipient]);
        }

        $this->sent[] = ['channel' => $channel, 'recipient' => $recipient, 'body' => $body, 'status' => $status];

        return $ok;
    }
}
