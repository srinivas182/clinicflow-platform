<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Actions;

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Branches\Support\BranchContext;
use App\Domains\Identity\Models\Staff;
use App\Domains\Pharmacy\Models\OwingItem;
use App\Domains\Pharmacy\Models\RegisterEntry;
use App\Domains\Pharmacy\Models\StockBatch;
use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\PrescriptionItem;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dispenses the current signed version of a script from in-house stock:
 * earliest expiry first, expired batches never used. What is short goes on
 * the owing list and is not billed. S5/S6 movements go to the register.
 * The visit moves to Dispatch with a 4-digit collection code.
 */
class DispensePrescription
{
    public function __construct(private readonly AddInvoiceLine $addLine, private readonly TransitionVisit $transition) {}

    /**
     * @return array{dispensed: int, owing: int, code: string}
     */
    public function handle(Prescription $prescription, Visit $visit, Staff $pharmacist): array
    {
        if (! $prescription->isDispensable()) {
            throw ValidationException::withMessages(['prescription' => 'Only the newest signed version of a script can be dispensed.']);
        }
        if ($visit->stage !== VisitStage::Pharmacy || $visit->patient_id !== $prescription->patient_id) {
            throw ValidationException::withMessages(['visit' => 'This patient is not waiting at the pharmacy.']);
        }
        if ($prescription->items()->exists() && DB::table('dispensings')->where('prescription_id', $prescription->id)->whereNull('returned_at')->exists()) {
            throw ValidationException::withMessages(['prescription' => 'This script has already been dispensed.']);
        }

        return DB::transaction(function () use ($prescription, $visit, $pharmacist): array {
            $invoice = Invoice::query()->where('visit_id', $visit->id)->firstOrFail();
            $dispensed = 0;
            $owing = 0;

            foreach ($prescription->items()->get() as $item) {
                /** @var PrescriptionItem $item */
                $stock = StockItem::query()->where('medicine_id', $item->medicine_id)->lockForUpdate()->first();
                $remaining = $item->quantity;
                $given = 0;

                if ($stock instanceof StockItem) {
                    $batches = $stock->batches()->whereDate('expiry_date', '>=', today())->where('quantity', '>', 0)->when(BranchContext::filterId(), fn ($q, int $b) => $q->where('branch_id', $b))->orderBy('expiry_date')->lockForUpdate()->get();
                    foreach ($batches as $batch) {
                        /** @var StockBatch $batch */
                        if ($remaining === 0) {
                            break;
                        }
                        $take = min($remaining, $batch->quantity);
                        $batch->decrement('quantity', $take);
                        DB::table('dispensings')->insert([
                            'prescription_id' => $prescription->id, 'prescription_item_id' => $item->id, 'visit_id' => $visit->id,
                            'stock_batch_id' => $batch->id, 'quantity' => $take, 'dispensed_by' => $pharmacist->id, 'dispensed_at' => now(),
                        ]);
                        $remaining -= $take;
                        $given += $take;
                    }

                    if ($given > 0) {
                        $this->addLine->handle($invoice, LineKind::Medicine, $item->description, $stock->unit_price_cents, $given, $item->nappi_code)
                            ->forceFill(['attributed_staff_id' => $prescription->prescriber_staff_id])->save();
                        RegisterEntry::record($stock, 'dispensed', -$given, [
                            'patient_id' => $prescription->patient_id, 'prescription_id' => $prescription->id,
                            'prescriber_staff_id' => $prescription->prescriber_staff_id, 'pharmacist_staff_id' => $pharmacist->id,
                        ]);
                    }
                }

                if ($remaining > 0) {
                    OwingItem::create([
                        'prescription_id' => $prescription->id, 'prescription_item_id' => $item->id, 'patient_id' => $prescription->patient_id,
                        'visit_id' => $visit->id, 'quantity' => $remaining, 'status' => 'owing',
                    ]);
                    $owing++;
                }
                $dispensed += $given > 0 ? 1 : 0;
            }

            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $visit->forceFill(['collection_code' => $code, 'ready_for_collection_at' => now()])->save();
            $this->transition->handle($visit, VisitStage::Dispatch, User::query()->find($pharmacist->id));

            activity('pharmacy')->performedOn($prescription)->withProperties(['dispensed_lines' => $dispensed, 'owing_lines' => $owing])->log('Script dispensed');

            return ['dispensed' => $dispensed, 'owing' => $owing, 'code' => $code];
        });
    }
}
