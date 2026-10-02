<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * Payment gateways supported in South Africa (client's choice).
 */
enum Gateway: string
{
    case PayFast = 'payfast';
    case Paystack = 'paystack';
    case Peach = 'peach';
    case Yoco = 'yoco';

    public function label(): string
    {
        return match ($this) {
            self::PayFast => 'PayFast',
            self::Paystack => 'Paystack',
            self::Peach => 'Peach Payments',
            self::Yoco => 'Yoco',
        };
    }

    /**
     * Credential fields shown in settings. Secret fields are never sent back to the browser.
     *
     * @return list<array{key: string, label: string, secret: bool}>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::PayFast => [
                ['key' => 'merchant_id', 'label' => 'Merchant ID', 'secret' => false],
                ['key' => 'merchant_key', 'label' => 'Merchant key', 'secret' => true],
                ['key' => 'passphrase', 'label' => 'Passphrase', 'secret' => true],
            ],
            self::Paystack => [
                ['key' => 'public_key', 'label' => 'Public key', 'secret' => false],
                ['key' => 'secret_key', 'label' => 'Secret key', 'secret' => true],
            ],
            self::Peach => [
                ['key' => 'entity_id', 'label' => 'Entity ID', 'secret' => false],
                ['key' => 'merchant_id', 'label' => 'Merchant ID', 'secret' => false],
                ['key' => 'client_id', 'label' => 'Client ID', 'secret' => false],
                ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true],
            ],
            self::Yoco => [
                ['key' => 'secret_key', 'label' => 'Secret key', 'secret' => true],
                ['key' => 'webhook_secret', 'label' => 'Webhook secret (whsec_…)', 'secret' => true],
            ],
        };
    }

    /**
     * Refunds through the gateway API. Others are refunded in the gateway's
     * own dashboard and recorded in Clinic Flow.
     */
    public function supportsApiRefund(): bool
    {
        return in_array($this, [self::Paystack, self::Yoco], true);
    }
}
