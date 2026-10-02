<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\GatewayConfig;
use App\Domains\Billing\Models\Payment;
use Illuminate\Http\Request;

/**
 * Provider webhook: verify with the provider's own credentials, match our
 * reference and amount, then confirm the payment (idempotent).
 */
class HandleGatewayWebhook
{
    public function __construct(private readonly RecordPayment $payments) {}

    public function handle(Gateway $gateway, Request $request): bool
    {
        $config = GatewayConfig::query()->where('gateway', $gateway->value)->first();
        if (! $config instanceof GatewayConfig) {
            return false;
        }

        $result = GatewayFactory::fromConfig($config)->handleWebhook($request);
        if ($result === null || ! $result->paid) {
            return false;
        }

        $payment = Payment::query()->where('checkout_token', $result->reference)->where('gateway', $gateway->value)->first();
        if (! $payment instanceof Payment) {
            return false;
        }

        if ($result->amountCents !== null && $result->amountCents !== $payment->amount_cents) {
            activity('billing')->performedOn($payment->invoice)->withProperties(['expected' => $payment->amount_cents, 'got' => $result->amountCents])->log('Webhook amount mismatch');

            return false;
        }

        if ($payment->status === PaymentStatus::Pending) {
            $this->payments->confirm($payment, $result->gatewayReference);
        }

        return true;
    }
}
