<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refunds all or part of a payment, with a reason. Pay-link payments are
 * refunded through the gateway; cash, card machine and EFT refunds are
 * recorded (paid out by the practice) with an optional reference.
 */
class RefundPayment
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(Payment $payment, int $amountCents, string $reason, ?User $by = null, ?string $reference = null): Refund
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the refund.']);
        }
        if ($amountCents <= 0 || $amountCents > $payment->refundableCents()) {
            throw ValidationException::withMessages(['amount' => 'The refund must be more than zero and no more than what was paid.']);
        }

        return DB::transaction(function () use ($payment, $amountCents, $reason, $by, $reference): Refund {
            if ($payment->method === PaymentMethod::PayLink && $payment->reference !== null) {
                $result = $this->gateway->refund($payment->reference, $amountCents);
                if (! $result->ok) {
                    throw ValidationException::withMessages(['amount' => $result->error ?? 'The gateway refused the refund.']);
                }
                $reference = $result->reference;
            }

            $refund = $payment->refunds()->create([
                'amount_cents' => $amountCents,
                'reason' => trim($reason),
                'status' => 'completed',
                'reference' => $reference,
                'issued_by' => $by?->id,
            ]);

            $payment->increment('refunded_cents', $amountCents);
            $payment->invoice->recalculate();

            activity('billing')->performedOn($payment->invoice)->causedBy($by)
                ->withProperties(['amount_cents' => $amountCents, 'reason' => $reason])
                ->log('Refund issued');

            return $refund;
        });
    }
}
