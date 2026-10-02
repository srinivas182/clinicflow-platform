<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Actions;

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Identity\Models\Staff;
use App\Domains\Pharmacy\Models\OwingItem;
use App\Domains\Pharmacy\Models\RegisterEntry;
use App\Domains\Pharmacy\Models\StockBatch;
use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\PrescriptionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supplies an owing item once stock arrives, and bills it then.
 */
class FulfilOwing
{
    public function __construct(private readonly AddInvoiceLine $addLine) {}

    public function handle(OwingItem $owing, Staff $pharmacist): OwingItem
    {
        if ($owing->status !== 'owing') {
            throw ValidationException::withMessages(['owing' => 'This item is no longer owing.']);
        }

        return DB::transaction(function () use ($owing, $pharmacist): OwingItem {
            $item = PrescriptionItem::query()->findOrFail($owing->prescription_item_id);
            $stock = StockItem::query()->where('medicine_id', $item->medicine_id)->lockForUpdate()->first();
            if (! $stock instanceof StockItem || $stock->onHand() < $owing->quantity) {
                throw ValidationException::withMessages(['owing' => 'Not enough stock yet to supply this item.']);
            }

            $remaining = $owing->quantity;
            foreach ($stock->batches()->whereDate('expiry_date', '>=', today())->where('quantity', '>', 0)->orderBy('expiry_date')->lockForUpdate()->get() as $batch) {
                /** @var StockBatch $batch */
                if ($remaining === 0) {
                    break;
                }
                $take = min($remaining, $batch->quantity);
                $batch->decrement('quantity', $take);
                DB::table('dispensings')->insert([
                    'prescription_id' => $owing->prescription_id, 'prescription_item_id' => $item->id, 'visit_id' => $owing->visit_id,
                    'stock_batch_id' => $batch->id, 'quantity' => $take, 'dispensed_by' => $pharmacist->id, 'dispensed_at' => now(),
                ]);
                $remaining -= $take;
            }

            $invoice = Invoice::query()->where('visit_id', $owing->visit_id)->firstOrFail();
            $prescriber = Prescription::query()->whereKey($owing->prescription_id)->value('prescriber_staff_id');
            $this->addLine->handle($invoice, LineKind::Medicine, $item->description, $stock->unit_price_cents, $owing->quantity, $item->nappi_code)
                ->forceFill(['attributed_staff_id' => $prescriber])->save();
            $prescription = Prescription::query()->findOrFail($owing->prescription_id);
            RegisterEntry::record($stock, 'dispensed', -$owing->quantity, [
                'patient_id' => $owing->patient_id, 'prescription_id' => $owing->prescription_id,
                'prescriber_staff_id' => $prescription->prescriber_staff_id, 'pharmacist_staff_id' => $pharmacist->id, 'reference' => 'Owing supplied',
            ]);

            $owing->forceFill(['status' => 'fulfilled', 'fulfilled_at' => now()])->save();
            activity('pharmacy')->performedOn($owing)->log('Owing item supplied');

            return $owing;
        });
    }
}
