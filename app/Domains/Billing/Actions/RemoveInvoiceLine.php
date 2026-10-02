<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\InvoiceLine;
use Illuminate\Validation\ValidationException;

/**
 * Removes an unpaid line. A paid line is never silently rewritten: it must be
 * refunded instead.
 */
class RemoveInvoiceLine
{
    public function handle(InvoiceLine $line): void
    {
        if ($line->locked_at !== null) {
            throw ValidationException::withMessages(['line' => 'This line has been paid. Issue a refund instead of removing it.']);
        }

        $invoice = Invoice::query()->findOrFail($line->invoice_id);
        $line->delete();
        $invoice->recalculate();
    }
}
