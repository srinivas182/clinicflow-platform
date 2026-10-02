<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\InvoiceLine;
use Illuminate\Validation\ValidationException;

/**
 * Adds a line to an open invoice. Lines are added through the visit
 * (consult at check-in, procedures, medicines) and never rewrite paid lines.
 */
class AddInvoiceLine
{
    public function handle(Invoice $invoice, LineKind $kind, string $description, int $unitCents, int $quantity = 1, ?string $code = null): InvoiceLine
    {
        if ($invoice->status === InvoiceStatus::Void) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is void.']);
        }

        if ($unitCents < 0 || $quantity < 1) {
            throw ValidationException::withMessages(['unit_cents' => 'Amounts must be positive.']);
        }

        $line = $invoice->lines()->create([
            'kind' => $kind,
            'code' => $code,
            'description' => $description,
            'quantity' => $quantity,
            'unit_cents' => $unitCents,
            'total_cents' => $unitCents * $quantity,
        ]);

        $invoice->recalculate();

        return $line;
    }
}
