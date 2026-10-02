<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\GatewayConfig;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Refund;
use App\Domains\Billing\Support\FakePaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refunds all or part of a payment, with a reason.
 *  - Paystack and Yoco pay-link payments are refunded through the gateway API.
 *  - PayFast and Peach pay-link payments are refunded in the gateway dashboard
 *    and recorded here ("manual").
 *  - Cash, card machine and EFT refunds are paid out by the practice and recorded.
 */
class RefundPayment
{
    public function handle(Payment $payment, int $amountCents, string $reason, ?User $by = null, ?string $reference = null): Refund
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the refund.']);
        }
        if ($amountCents <= 0 || $amountCents > $payment->refundableCents()) {
            throw ValidationException::withMessages(['amount' => 'The refund must be more than zero and no more than what was paid.']);
        }

        return DB::transaction(function () use ($payment, $amountCents, $reason, $by, $reference): Refund {
            $status = 'completed';

            if ($payment->method === PaymentMethod::PayLink) {
                $adapter = $this->adapterFor($payment);
                $gateway = Gateway::tryFrom((string) $payment->gateway);

                if ($adapter !== null && ($gateway === null || $gateway->supportsApiRefund())) {
                    $result = $adapter->refund((string) ($payment->gateway_reference ?? $payment->checkout_token), $amountCents);
                    if (! $result->ok) {
                        throw ValidationException::withMessages(['amount' => $result->error ?? 'The gateway refused the refund.']);
                    }
                    $reference = $result->reference;
                } else {
                    $status = 'manual';
                }
            }

            $refund = $payment->refunds()->create([
                'amount_cents' => $amountCents,
                'reason' => trim($reason),
                'status' => $status,
                'reference' => $reference,
                'issued_by' => $by?->id,
            ]);

            $payment->increment('refunded_cents', $amountCents);
            $payment->invoice->recalculate();

            activity('billing')->performedOn($payment->invoice)->causedBy($by)
                ->withProperties(['amount_cents' => $amountCents, 'reason' => $reason, 'status' => $status])
                ->log('Refund issued');

            return $refund;
        });
    }

    private function adapterFor(Payment $payment): ?PaymentGateway
    {
        if ($payment->gateway === 'fake') {
            return app(FakePaymentGateway::class);
        }

        $config = GatewayConfig::query()->where('gateway', (string) $payment->gateway)->first();

        return $config instanceof GatewayConfig ? GatewayFactory::fromConfig($config) : null;
    }
}
