<?php

declare(strict_types=1);

namespace App\Domains\Finance\Support;

use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Models\CreditNote;
use App\Domains\Billing\Models\InvoiceLine;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\Refund;
use App\Domains\Finance\Models\LedgerEntry;

/**
 * Posts balanced journal entries as billing events happen:
 *  - invoice line: Dr receivable / Cr revenue:<kind>
 *  - payment (succeeded): Dr bank:<method> / Cr receivable
 *  - refund: Dr receivable / Cr bank:<method>
 *  - credit note: Dr revenue:credit_notes / Cr receivable
 */
final class LedgerPoster
{
    public static function register(): void
    {
        InvoiceLine::created(fn (InvoiceLine $l) => self::pair('receivable', 'revenue:'.$l->kind->value, $l->total_cents, 'invoice_line', (string) $l->id, $l->description));

        InvoiceLine::deleted(fn (InvoiceLine $l) => self::pair('revenue:'.$l->kind->value, 'receivable', $l->total_cents, 'invoice_line_removed', (string) $l->id, 'Removed: '.$l->description));

        Payment::saved(function (Payment $p): void {
            $becameSucceeded = $p->status === PaymentStatus::Succeeded && ($p->wasRecentlyCreated || $p->wasChanged('status'));
            if ($becameSucceeded && ! LedgerEntry::query()->where('source_type', 'payment')->where('source_id', (string) $p->id)->exists()) {
                self::pair('bank:'.$p->method->value, 'receivable', $p->amount_cents, 'payment', (string) $p->id, $p->method->label().' payment');
            }
        });

        Refund::created(function (Refund $r): void {
            $method = Payment::query()->whereKey($r->payment_id)->value('method');
            self::pair('receivable', 'bank:'.(is_string($method) ? $method : 'unknown'), $r->amount_cents, 'refund', (string) $r->id, 'Refund: '.$r->reason);
        });

        CreditNote::created(fn (CreditNote $c) => self::pair('revenue:credit_notes', 'receivable', $c->amount_cents, 'credit_note', (string) $c->id, "Credit note {$c->number}"));
    }

    private static function pair(string $debit, string $credit, int $cents, string $type, string $id, string $description): void
    {
        if ($cents <= 0 || tenant() === null) {
            return;
        }

        $base = ['occurred_at' => now(), 'source_type' => $type, 'source_id' => $id, 'description' => mb_substr($description, 0, 255)];
        LedgerEntry::create([...$base, 'account' => $debit, 'debit_cents' => $cents]);
        LedgerEntry::create([...$base, 'account' => $credit, 'credit_cents' => $cents]);
    }
}
