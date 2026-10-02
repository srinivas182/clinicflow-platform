<?php

declare(strict_types=1);

namespace App\Domains\Billing\Gateways;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Models\GatewayConfig;
use App\Domains\Billing\Models\PlatformGatewayConfig;
use App\Domains\Billing\Support\FakePaymentGateway;

/**
 * Builds the adapter for a stored configuration: the provider's own account
 * inside a workspace, the platform's account for subscriptions.
 */
final class GatewayFactory
{
    /**
     * @param  array<string, string>  $credentials
     */
    public static function make(Gateway $gateway, array $credentials, GatewayMode $mode): PaymentGateway
    {
        return match ($gateway) {
            Gateway::PayFast => new PayFastGateway($credentials, $mode),
            Gateway::Paystack => new PaystackGateway($credentials, $mode),
            Gateway::Peach => new PeachGateway($credentials, $mode),
            Gateway::Yoco => new YocoGateway($credentials, $mode),
        };
    }

    public static function fromConfig(GatewayConfig|PlatformGatewayConfig $config): PaymentGateway
    {
        return self::make($config->gateway, $config->credentials ?? [], $config->mode);
    }

    /**
     * The provider's default enabled gateway, limited to gateways the platform offers.
     */
    public static function providerDefault(): ?GatewayConfig
    {
        $offered = PlatformGatewayConfig::offeredGateways();

        return GatewayConfig::query()->where('enabled', true)
            ->whereIn('gateway', $offered)
            ->orderByDesc('is_default')->orderBy('id')->first();
    }

    public static function platformDefault(): ?PlatformGatewayConfig
    {
        return PlatformGatewayConfig::query()->where('enabled', true)->orderByDesc('is_default')->orderBy('id')->first();
    }

    public static function forProvider(): PaymentGateway
    {
        $config = self::providerDefault();

        if ($config !== null) {
            return self::fromConfig($config);
        }

        if ((bool) config('clinicflow.payments.allow_fake')) {
            return app(FakePaymentGateway::class);
        }

        throw new GatewayException('Connect a payment gateway in Settings → Payments to send pay links.');
    }
}
