<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Actions;

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Clinical\Models\Quote;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Procedure quotes: the doctor quotes, the patient accepts (medical aid
 * patients need a pre-authorisation number), and only then are the
 * procedure lines added to the invoice.
 */
class ManageQuote
{
    public function __construct(private readonly AddInvoiceLine $addLine) {}

    /**
     * @param  list<array{code: string, description: string, quantity: int, unit_cents: int}>  $lines
     */
    public function create(Visit $visit, array $lines, ?User $by = null): Quote
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one procedure item.']);
        }

        return Quote::create([
            'visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'lines' => $lines,
            'total_cents' => array_sum(array_map(fn (array $l) => $l['unit_cents'] * $l['quantity'], $lines)),
            'status' => 'draft',
            'created_by' => $by?->id,
        ]);
    }

    public function accept(Quote $quote, ?string $preauthNumber = null, ?User $by = null): Quote
    {
        if ($quote->status !== 'draft') {
            throw ValidationException::withMessages(['quote' => 'This quote has already been answered.']);
        }

        $visit = Visit::query()->findOrFail($quote->visit_id);
        if ($visit->payer_type === PayerType::MedicalAid && blank($preauthNumber)) {
            throw ValidationException::withMessages(['preauth_number' => 'Enter the medical aid pre-authorisation number.']);
        }

        return DB::transaction(function () use ($quote, $visit, $preauthNumber, $by): Quote {
            $invoice = Invoice::query()->where('visit_id', $visit->id)->firstOrFail();
            foreach ($quote->lines as $line) {
                $this->addLine->handle($invoice, LineKind::Procedure, $line['description'], $line['unit_cents'], $line['quantity'], $line['code']);
            }
            $quote->forceFill(['status' => 'accepted', 'preauth_number' => $preauthNumber, 'accepted_at' => now()])->save();
            activity('billing')->performedOn($quote)->causedBy($by)->withProperties(['total_cents' => $quote->total_cents])->log('Quote accepted');

            return $quote;
        });
    }

    public function decline(Quote $quote, ?User $by = null): Quote
    {
        if ($quote->status !== 'draft') {
            throw ValidationException::withMessages(['quote' => 'This quote has already been answered.']);
        }
        $quote->forceFill(['status' => 'declined'])->save();
        activity('billing')->performedOn($quote)->causedBy($by)->log('Quote declined');

        return $quote;
    }
}
