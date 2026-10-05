<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Gateways;

use App\Domains\Messaging\Enums\MessagingDriver;
use App\Domains\Messaging\Mail\PlatformMessageMail;
use App\Domains\Messaging\Models\MessagingProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends email over SMTP: Amazon SES SMTP (e.g. af-south-1) or any SMTP server.
 */
final class EmailGateway
{
    /**
     * @return array{ok: bool, error: ?string}
     */
    public static function send(MessagingProvider $provider, string $to, string $subject, string $body, string $fromName, ?string $replyTo, ?string $fromEmail = null): array
    {
        $c = $provider->credentials ?? [];
        $from = (string) ($fromEmail ?: $provider->sender ?: config('mail.from.address'));

        if ($provider->driver === MessagingDriver::SendGrid || $provider->driver === MessagingDriver::Brevo) {
            return self::sendViaApi($provider->driver, (string) ($c['api_key'] ?? ''), $to, $subject, $body, $from, $fromName, $replyTo);
        }

        $config = $provider->driver === MessagingDriver::Ses
            ? ['transport' => 'smtp', 'host' => 'email-smtp.'.($c['region'] ?? 'af-south-1').'.amazonaws.com', 'port' => 587, 'username' => $c['smtp_username'] ?? '', 'password' => $c['smtp_password'] ?? '', 'encryption' => 'tls']
            : ['transport' => 'smtp', 'host' => $c['host'] ?? '', 'port' => (int) ($c['port'] ?? 587), 'username' => $c['username'] ?? '', 'password' => $c['password'] ?? '', 'encryption' => ($c['encryption'] ?? 'tls') ?: null];

        config(['mail.mailers.clinicflow_runtime' => $config]);
        $root = Mail::getFacadeRoot();
        if (is_object($root) && method_exists($root, 'purge')) {
            $root->purge('clinicflow_runtime');
        }

        try {
            Mail::mailer('clinicflow_runtime')->to($to)->send(new PlatformMessageMail($subject, $body, (string) ($provider->sender ?: config('mail.from.address')), $fromName, $replyTo));

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Twilio SendGrid (v3 mail/send) and Brevo (v3 smtp/email) HTTP APIs.
     *
     * @return array{ok: bool, error: ?string}
     */
    private static function sendViaApi(MessagingDriver $driver, string $apiKey, string $to, string $subject, string $body, string $from, string $fromName, ?string $replyTo): array
    {
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'API key missing.'];
        }

        try {
            if ($driver === MessagingDriver::SendGrid) {
                $response = Http::withToken($apiKey)->acceptJson()->post('https://api.sendgrid.com/v3/mail/send', [
                    'personalizations' => [['to' => [['email' => $to]]]],
                    'from' => ['email' => $from, 'name' => $fromName],
                    ...($replyTo ? ['reply_to' => ['email' => $replyTo]] : []),
                    'subject' => $subject,
                    'content' => [['type' => 'text/plain', 'value' => $body]],
                ]);
            } else {
                $response = Http::withHeaders(['api-key' => $apiKey])->acceptJson()->post('https://api.brevo.com/v3/smtp/email', [
                    'sender' => ['email' => $from, 'name' => $fromName],
                    'to' => [['email' => $to]],
                    ...($replyTo ? ['replyTo' => ['email' => $replyTo]] : []),
                    'subject' => $subject,
                    'textContent' => $body,
                ]);
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'error' => null];
        }

        $message = $response->json('errors.0.message') ?? $response->json('message');

        return ['ok' => false, 'error' => is_string($message) ? $message : "{$driver->label()} returned HTTP {$response->status()}."];
    }
}
