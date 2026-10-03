<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Gateways;

use App\Domains\Messaging\Enums\MessagingDriver;
use App\Domains\Messaging\Mail\PlatformMessageMail;
use App\Domains\Messaging\Models\MessagingProvider;
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
    public static function send(MessagingProvider $provider, string $to, string $subject, string $body, string $fromName, ?string $replyTo): array
    {
        $c = $provider->credentials ?? [];
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
}
