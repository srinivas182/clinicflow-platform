<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Actions;

use App\Domains\Billing\Support\Vat;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Pharmacy\Models\PurchaseOrder;
use App\Domains\Pharmacy\Models\PurchaseOrderLine;
use App\Domains\Pharmacy\Models\RegisterEntry;
use App\Domains\Pharmacy\Models\StockBatch;
use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Pharmacy\Models\Supplier;
use App\Domains\Prescribing\Contracts\DrugDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders, receiving against them (batch, expiry, cost), stock takes
 * and expiry write-offs. Every S5/S6 movement goes to the register; input VAT
 * is recorded for VAT-registered suppliers.
 */
class Procurement
{
    public function __construct(private readonly DrugDatabase $drugs, private readonly ReceiveStock $receive) {}

    /**
     * @param  list<array{medicine_id: int, quantity: int, unit_cost_cents: int}>  $lines
     */
    public function order(Supplier $supplier, array $lines, int $by): PurchaseOrder
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one item.']);
        }

        return DB::transaction(function () use ($supplier, $lines, $by): PurchaseOrder {
            $prefix = 'PO-'.now()->format('Y').'-';
            $next = PurchaseOrder::query()->where('number', 'like', $prefix.'%')->lockForUpdate()->count() + 1;
            $po = PurchaseOrder::create(['number' => $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT), 'supplier_id' => $supplier->id, 'status' => 'draft', 'ordered_by' => $by]);
            $total = 0;
            foreach ($lines as $i => $l) {
                $medicine = $this->drugs->find((int) $l['medicine_id']);
                if ($medicine === null || $l['quantity'] < 1) {
                    throw ValidationException::withMessages(["lines.{$i}" => 'Choose a medicine and a quantity.']);
                }
                $po->lines()->create(['medicine_id' => $medicine->id, 'description' => $medicine->label(), 'quantity' => $l['quantity'], 'unit_cost_cents' => $l['unit_cost_cents']]);
                $total += $l['quantity'] * $l['unit_cost_cents'];
            }
            $po->forceFill(['total_cents' => $total, 'vat_cents' => $supplier->vat_number ? Vat::exclusive($total) : 0])->save();

            return $po;
        });
    }

    public function send(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw ValidationException::withMessages(['order' => 'Only draft orders can be sent.']);
        }
        $po->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        $email = $po->supplier->email;
        if (is_string($email) && $email !== '') {
            $lines = $po->lines()->get()->map(fn (PurchaseOrderLine $l) => "{$l->quantity} × {$l->description}")->implode("\n");
            app(SendMessage::class)->handle('email', $email, "Purchase order {$po->number}\n\n{$lines}", "Purchase order {$po->number}", 'purchase_order', (string) $po->id);
        }

        return $po;
    }

    /**
     * @param  list<array{line_id: int, quantity: int, batch: string, expiry: string, sell_price_cents?: ?int}>  $receipts
     */
    public function receive(PurchaseOrder $po, array $receipts, int $by): PurchaseOrder
    {
        if (! in_array($po->status, ['sent', 'partial'], true)) {
            throw ValidationException::withMessages(['order' => 'Only sent orders can be received.']);
        }

        return DB::transaction(function () use ($po, $receipts, $by): PurchaseOrder {
            foreach ($receipts as $r) {
                $line = $po->lines()->whereKey($r['line_id'])->lockForUpdate()->firstOrFail();
                if ($r['quantity'] < 1 || $line->received_quantity + $r['quantity'] > $line->quantity) {
                    throw ValidationException::withMessages(['quantity' => 'Receive between 1 and '.($line->quantity - $line->received_quantity)." of {$line->description}."]);
                }
                $price = $r['sell_price_cents'] ?? StockItem::query()->where('medicine_id', $line->medicine_id)->value('unit_price_cents') ?? $line->unit_cost_cents;
                $this->receive->handle($line->medicine_id, $r['batch'], CarbonImmutable::parse($r['expiry']), $r['quantity'], (int) $price, $by);
                $line->increment('received_quantity', $r['quantity']);
            }
            $open = $po->lines()->whereColumn('received_quantity', '<', 'quantity')->exists();
            $po->forceFill(['status' => $open ? 'partial' : 'received'])->save();

            return $po;
        });
    }

    /**
     * Counted stock differs from the system: adjust with a reason (removing earliest expiry first).
     */
    public function stockTake(StockItem $item, int $counted, string $reason, int $by): int
    {
        if (trim($reason) === '' || $counted < 0) {
            throw ValidationException::withMessages(['reason' => 'Enter the counted quantity and a reason for any difference.']);
        }

        return DB::transaction(function () use ($item, $counted, $reason, $by): int {
            $diff = $counted - $item->onHand();
            if ($diff === 0) {
                return 0;
            }
            if ($diff > 0) {
                $latest = $item->batches()->whereDate('expiry_date', '>=', today())->orderByDesc('expiry_date')->first();
                $batch = $latest instanceof StockBatch ? $latest : $item->batches()->create(['batch_number' => 'ADJ-'.now()->format('ymd'), 'expiry_date' => now()->addYear(), 'quantity' => 0]);
                $batch->increment('quantity', $diff);
            } else {
                $remaining = -$diff;
                foreach ($item->batches()->whereDate('expiry_date', '>=', today())->where('quantity', '>', 0)->orderBy('expiry_date')->lockForUpdate()->get() as $batch) {
                    /** @var StockBatch $batch */
                    $take = min($remaining, $batch->quantity);
                    $batch->decrement('quantity', $take);
                    $remaining -= $take;
                    if ($remaining === 0) {
                        break;
                    }
                }
            }
            DB::table('stock_adjustments')->insert(['stock_item_id' => $item->id, 'kind' => 'stock_take', 'quantity' => $diff, 'reason' => trim($reason), 'by' => $by, 'created_at' => now()]);
            RegisterEntry::record($item, 'adjusted', $diff, ['pharmacist_staff_id' => $by, 'reference' => 'Stock take: '.trim($reason)]);

            return $diff;
        });
    }

    public function writeOffExpired(int $by): int
    {
        $count = 0;
        StockBatch::query()->whereDate('expiry_date', '<', today())->where('quantity', '>', 0)->get()->each(function (StockBatch $batch) use ($by, &$count): void {
            $qty = $batch->quantity;
            $batch->forceFill(['quantity' => 0])->save();
            $item = StockItem::query()->findOrFail($batch->stock_item_id);
            DB::table('stock_adjustments')->insert(['stock_item_id' => $item->id, 'stock_batch_id' => $batch->id, 'kind' => 'write_off', 'quantity' => -$qty, 'reason' => 'Expired '.$batch->expiry_date->toDateString(), 'by' => $by, 'created_at' => now()]);
            RegisterEntry::record($item, 'written_off', -$qty, ['pharmacist_staff_id' => $by, 'reference' => "Expired batch {$batch->batch_number}"]);
            $count++;
        });

        return $count;
    }
}
