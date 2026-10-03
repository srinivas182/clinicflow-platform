<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Enums;

/**
 * SMS and email suppliers the super admin can connect. All messages leave
 * from the platform's accounts; providers never configure suppliers.
 */
enum MessagingDriver: string
{
    case Clickatell = 'clickatell';
    case BulkSms = 'bulksms';
    case SmsPortal = 'smsportal';
    case Twilio = 'twilio';
    case Ses = 'ses';
    case Smtp = 'smtp';
    case SendGrid = 'sendgrid';
    case Brevo = 'brevo';

    public function channel(): string
    {
        return in_array($this, [self::Ses, self::Smtp, self::SendGrid, self::Brevo], true) ? 'email' : 'sms';
    }

    public function label(): string
    {
        return match ($this) {
            self::Clickatell => 'Clickatell',
            self::BulkSms => 'BulkSMS',
            self::SmsPortal => 'SMSPortal',
            self::Twilio => 'Twilio',
            self::Ses => 'Amazon SES (SMTP)',
            self::Smtp => 'SMTP server',
            self::SendGrid => 'Twilio SendGrid',
            self::Brevo => 'Brevo',
        };
    }

    /**
     * @return list<array{key: string, label: string, secret: bool}>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::Clickatell => [['key' => 'api_key', 'label' => 'API key', 'secret' => true]],
            self::BulkSms => [['key' => 'token_id', 'label' => 'Token ID', 'secret' => false], ['key' => 'token_secret', 'label' => 'Token secret', 'secret' => true]],
            self::SmsPortal => [['key' => 'client_id', 'label' => 'Client ID', 'secret' => false], ['key' => 'client_secret', 'label' => 'API secret', 'secret' => true]],
            self::Twilio => [['key' => 'account_sid', 'label' => 'Account SID', 'secret' => false], ['key' => 'auth_token', 'label' => 'Auth token', 'secret' => true]],
            self::Ses => [['key' => 'region', 'label' => 'AWS region (e.g. af-south-1)', 'secret' => false], ['key' => 'smtp_username', 'label' => 'SMTP username', 'secret' => false], ['key' => 'smtp_password', 'label' => 'SMTP password', 'secret' => true]],
            self::SendGrid => [['key' => 'api_key', 'label' => 'SendGrid API key', 'secret' => true]],
            self::Brevo => [['key' => 'api_key', 'label' => 'Brevo API key', 'secret' => true]],
            self::Smtp => [['key' => 'host', 'label' => 'Host', 'secret' => false], ['key' => 'port', 'label' => 'Port', 'secret' => false], ['key' => 'username', 'label' => 'Username', 'secret' => false], ['key' => 'password', 'label' => 'Password', 'secret' => true], ['key' => 'encryption', 'label' => 'Encryption (tls/ssl)', 'secret' => false]],
        };
    }

    public function senderLabel(): string
    {
        return $this->channel() === 'email' ? 'From address (on the platform domain)' : ($this === self::Twilio ? 'From number or approved sender ID' : 'Sender ID (optional, if approved)');
    }
}
