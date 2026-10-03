<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\CreditNote;
use App\Domains\Billing\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Credits part of an invoice (e.g. medicine returned to stock). Paid lines are
 * never edited; the credit reduces what is owed and, if the patient has
 * already paid more than the new total, flags a refund.
 */
class IssueCreditNote
{
    public function handle(Invoice $invoice, int $amountCents, string $reason, ?User $by = null): CreditNote
    {
        $creditable = (int) $invoice->lines()->sum('total_cents') - $invoice->credited_cents;
        if ($amountCents <= 0 || $amountCents > $creditable) {
            throw ValidationException::withMessages(['amount' => 'The credit must be more than zero and no more than the invoice value.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the credit note.']);
        }

        return DB::transaction(function () use ($invoice, $amountCents, $reason, $by): CreditNote {
            $prefix = 'CN-'.now()->format('Y').'-';
            $next = CreditNote::query()->where('number', 'like', $prefix.'%')->lockForUpdate()->count() + 1;
            $note = CreditNote::create([
                'number' => $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT),
                'invoice_id' => $invoice->id,
                'amount_cents' => $amountCents,
                'vat_cents' => $invoice->tax_invoice ? (int) round($amountCents * (float) $invoice->vat_rate / (100 + (float) $invoice->vat_rate)) : 0,
                'reason' => trim($reason),
                'issued_by' => $by?->id,
            ]);

            $invoice->recalculate();
            if ($invoice->paid_cents > $invoice->total_cents) {
                $over = $invoice->paid_cents - $invoice->total_cents;
                $invoice->forceFill(['needs_review' => true, 'review_note' => 'Credit note '.$note->number.' — refund R'.number_format($over / 100, 2, '.', ' ').' to the patient.'])->save();
            }

            activity('billing')->performedOn($invoice)->causedBy($by)->withProperties(['credit_note' => $note->number, 'amount_cents' => $amountCents])->log('Credit note issued');

            return $note;
        });
    }
}
