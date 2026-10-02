<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts;

use App\Domains\Billing\Gateways\CheckoutRequest;
use App\Domains\Billing\Gateways\CheckoutStart;
use App\Domains\Billing\Gateways\WebhookResult;
use App\Domains\Billing\Support\GatewayResult;
use Illuminate\Http\Request;

/**
 * One merchant account at PayFast, Paystack, Peach Payments or Yoco — either
 * a provider's own (patient payments) or the platform's (subscriptions).
 * Money never passes through Clinic Flow (ADR 0009).
 */
interface PaymentGateway
{
    public function name(): string;

    public function startCheckout(CheckoutRequest $request): CheckoutStart;

    /**
     * Verify an incoming notification. Returns null when it is not genuine
     * or not about a payment.
     */
    public function handleWebhook(Request $request): ?WebhookResult;

    public function refund(string $gatewayReference, int $amountCents): GatewayResult;

    public function testConnection(): GatewayResult;
}
