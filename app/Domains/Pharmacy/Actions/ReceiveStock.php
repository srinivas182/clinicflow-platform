<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Actions;

use App\Domains\Pharmacy\Models\RegisterEntry;
use App\Domains\Pharmacy\Models\StockBatch;
use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Prescribing\Contracts\DrugDatabase;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveStock
{
    public function __construct(private readonly DrugDatabase $drugs) {}

    public function handle(int $medicineId, string $batchNumber, CarbonInterface $expiry, int $quantity, int $unitPriceCents, ?int $by = null): StockBatch
    {
        $medicine = $this->drugs->find($medicineId);
        if ($medicine === null) {
            throw ValidationException::withMessages(['medicine_id' => 'Unknown medicine.']);
        }
        if ($quantity < 1 || $expiry->isPast()) {
            throw ValidationException::withMessages(['quantity' => 'Receive a positive quantity of unexpired stock.']);
        }

        return DB::transaction(function () use ($medicine, $batchNumber, $expiry, $quantity, $unitPriceCents, $by): StockBatch {
            $item = StockItem::query()->updateOrCreate(['medicine_id' => $medicine->id], [
                'nappi_code' => $medicine->nappi_code, 'description' => $medicine->label(),
                'schedule' => $medicine->schedule, 'unit_price_cents' => $unitPriceCents,
            ]);
            $batch = $item->batches()->create(['batch_number' => $batchNumber, 'expiry_date' => $expiry, 'quantity' => $quantity]);
            RegisterEntry::record($item, 'received', $quantity, ['pharmacist_staff_id' => $by, 'reference' => "Batch {$batchNumber}"]);
            activity('pharmacy')->performedOn($item)->withProperties(['batch' => $batchNumber, 'quantity' => $quantity])->log('Stock received');

            return $batch;
        });
    }
}
