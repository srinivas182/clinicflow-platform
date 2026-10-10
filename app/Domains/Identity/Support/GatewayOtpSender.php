<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Models\MessagingProvider;
use App\Domains\Messaging\Support\MessageCatalogue;
use App\Models\User;

/**
 * Sends staff sign-in codes and signing PINs by SMS from the platform account
 * (not counted against any provider's allowance).
 */
class GatewayOtpSender implements OtpSender
{
    /** @var array<int, string> last code per user id */
    public array $sent = [];

    public function __construct(private readonly MessageSender $messages) {}

    public function send(User $user, string $code): void
    {
        $this->sent[$user->id] = $code;

        $text = MessageCatalogue::render((string) (MessageCatalogue::get('auth.sign_in_code')['sms'] ?? ''), ['code' => $code]);
        $hasPhone = is_string($user->phone) && $user->phone !== '';
        $hasEmail = $user->email !== '';
        // auto: SMS when an SMS supplier is set up, otherwise email (e.g. before SMS is configured).
        $channel = (string) config('clinicflow.security.signin_code_channel', 'auto');
        $useSms = $hasPhone && ($channel === 'sms' || ($channel === 'auto' && MessagingProvider::activeFor('sms') !== null));
        if ($useSms || ($hasPhone && ! $hasEmail)) {
            $this->messages->send('sms', (string) $user->phone, null, $text);
        } elseif ($hasEmail) {
            $this->messages->send('email', (string) $user->email, 'Your Clinic Flow sign-in code', $text);
        }
    }
}
