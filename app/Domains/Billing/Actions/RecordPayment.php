<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a payment into the provider's own account. Card machine and EFT need
 * the slip/bank reference; pay links go through the provider's gateway.
 * Paying locks the lines it covers so they can't be silently changed.
 */
class RecordPayment
{
    public function __construct(private readonly PaymentGateway $gateway) {}

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

        return DB::transaction(function () use ($invoice, $method, $amountCents, $reference, $by): Payment {
            $status = PaymentStatus::Succeeded;
            $gatewayName = null;

            if ($method === PaymentMethod::PayLink) {
                $result = $this->gateway->createPayLink($amountCents, $invoice->number, "Invoice {$invoice->number}");
                if (! $result->ok) {
                    throw ValidationException::withMessages(['method' => $result->error ?? 'The pay link could not be created.']);
                }
                $reference = $result->reference;
                $gatewayName = $this->gateway->name();
                $status = PaymentStatus::Pending;
            }

            $payment = $invoice->payments()->create([
                'method' => $method,
                'amount_cents' => $amountCents,
                'status' => $status,
                'reference' => $reference,
                'gateway' => $gatewayName,
                'received_by' => $by?->id,
            ]);

            if ($status === PaymentStatus::Succeeded) {
                $this->settle($invoice);
            }

            activity('billing')->performedOn($invoice)->causedBy($by)
                ->withProperties(['method' => $method->value, 'amount_cents' => $amountCents, 'status' => $status->value])
                ->log('Payment recorded');

            return $payment;
        });
    }

    /**
     * Called by the gateway webhook when a pay link is paid.
     */
    public function confirm(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return;
        }

        $payment->forceFill(['status' => PaymentStatus::Succeeded])->save();
        $this->settle($payment->invoice);
    }

    private function settle(Invoice $invoice): void
    {
        $invoice->lines()->whereNull('locked_at')->update(['locked_at' => now()]);
        $invoice->recalculate();
    }
}
