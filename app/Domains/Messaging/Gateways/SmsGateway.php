<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Gateways;

use App\Domains\Messaging\Enums\MessagingDriver;
use App\Domains\Messaging\Models\MessagingProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends one SMS through the configured supplier. Numbers are normalised to
 * international format (+27…). API details per supplier must be confirmed in
 * each supplier's sandbox before go-live.
 */
final class SmsGateway
{
    public static function e164(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        return '+'.(str_starts_with($digits, '0') ? '27'.substr($digits, 1) : $digits);
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public static function send(MessagingProvider $provider, string $to, string $body): array
    {
        $c = $provider->credentials ?? [];
        $number = self::e164($to);
        $sender = $provider->sender;

        $response = match ($provider->driver) {
            MessagingDriver::Clickatell => Http::withHeaders(['Authorization' => (string) ($c['api_key'] ?? '')])->acceptJson()
                ->post('https://platform.clickatell.com/v1/message', ['messages' => [array_filter([
                    'channel' => 'sms', 'to' => ltrim($number, '+'), 'content' => $body, 'from' => $sender,
                ])]]),
            MessagingDriver::BulkSms => Http::withBasicAuth((string) ($c['token_id'] ?? ''), (string) ($c['token_secret'] ?? ''))->acceptJson()
                ->post('https://api.bulksms.com/v1/messages', array_filter(['to' => $number, 'body' => $body, 'from' => $sender])),
            MessagingDriver::SmsPortal => self::smsPortal($c, $number, $body),
            MessagingDriver::Twilio => Http::withBasicAuth((string) ($c['account_sid'] ?? ''), (string) ($c['auth_token'] ?? ''))->asForm()
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.($c['account_sid'] ?? '').'/Messages.json', ['To' => $number, 'From' => (string) $sender, 'Body' => $body]),
            default => null,
        };

        if ($response === null) {
            return ['ok' => false, 'error' => 'Not an SMS supplier.'];
        }

        return $response->successful() ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'Supplier returned HTTP '.$response->status()];
    }

    /**
     * @param  array<string, string>  $c
     */
    private static function smsPortal(array $c, string $number, string $body): Response
    {
        $token = Http::withBasicAuth($c['client_id'] ?? '', $c['client_secret'] ?? '')->acceptJson()->get('https://rest.smsportal.com/Authentication')->json('token');

        return Http::withToken(is_string($token) ? $token : '')->acceptJson()
            ->post('https://rest.smsportal.com/BulkMessages', ['messages' => [['content' => $body, 'destination' => ltrim($number, '+')]]]);
    }
}
