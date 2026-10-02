<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Gateways\GatewayException;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records a payment into the provider's own account. Card machine and EFT need
 * the slip/bank reference. A pay link creates a pending payment with a secure
 * link; it only counts once the provider's gateway confirms it (webhook).
 * Paying locks the lines it covers so they can't be silently changed.
 */
class RecordPayment
{
    public function handle(Invoice $invoice, PaymentMethod $method, int $amountCents, ?string $reference = null, ?User $by = null): Payment
    {
        if ($invoice->status === InvoiceStatus::Void) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is void.']);
        }
        if ($amountCents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above zero.']);
        }
        if ($amountCents > $invoice->balanceCents()) {
            throw ValidationException::withMessages(['amount' => 'This is more than the amount due.']);
        }
        if ($method->needsReference() && blank($reference)) {
            throw ValidationException::withMessages(['reference' => 'Enter the card slip or EFT reference.']);
        }

        $gateway = null;
        $mode = null;
        if ($method === PaymentMethod::PayLink) {
            $config = GatewayFactory::providerDefault();
            if ($config !== null) {
                $gateway = $config->gateway->value;
                $mode = $config->mode->value;
            } elseif ((bool) config('clinicflow.payments.allow_fake')) {
                $gateway = 'fake';
            } else {
                throw ValidationException::withMessages(['method' => (new GatewayException('Connect a payment gateway in Settings → Payments to send pay links.'))->getMessage()]);
            }
        }

        return DB::transaction(function () use ($invoice, $method, $amountCents, $reference, $by, $gateway, $mode): Payment {
            $pending = $method === PaymentMethod::PayLink;

            $payment = $invoice->payments()->create([
                'method' => $method,
                'amount_cents' => $amountCents,
                'status' => $pending ? PaymentStatus::Pending : PaymentStatus::Succeeded,
                'reference' => $reference,
                'gateway' => $gateway,
                'gateway_mode' => $mode,
                'checkout_token' => $pending ? Str::random(48) : null,
                'received_by' => $by?->id,
            ]);

            if (! $pending) {
                $this->settle($invoice);
            }

            activity('billing')->performedOn($invoice)->causedBy($by)
                ->withProperties(['method' => $method->value, 'amount_cents' => $amountCents, 'gateway' => $gateway])
                ->log($pending ? 'Pay link created' : 'Payment recorded');

            return $payment;
        });
    }

    /**
     * Called when the gateway confirms a pay link (verified webhook). Idempotent.
     */
    public function confirm(Payment $payment, ?string $gatewayReference = null): void
    {
        DB::transaction(function () use ($payment, $gatewayReference): void {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== PaymentStatus::Pending) {
                return;
            }

            $locked->forceFill(['status' => PaymentStatus::Succeeded, 'gateway_reference' => $gatewayReference ?? $locked->gateway_reference])->save();
            $this->settle($locked->invoice);
            activity('billing')->performedOn($locked->invoice)->withProperties(['payment' => $locked->id])->log('Pay link paid');
        });
    }

    private function settle(Invoice $invoice): void
    {
        $invoice->lines()->whereNull('locked_at')->update(['locked_at' => now()]);
        $invoice->recalculate();
    }
}
