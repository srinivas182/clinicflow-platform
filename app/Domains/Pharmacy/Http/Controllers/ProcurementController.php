<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Pharmacy\Actions\Procurement;
use App\Domains\Pharmacy\Models\PurchaseOrder;
use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Pharmacy\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Suppliers, purchase orders, receiving, reorder list, stock takes and expiry write-offs.
 */
class ProcurementController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);

        return Inertia::render('Pharmacy/Procurement', [
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'email', 'vat_number']),
            'orders' => PurchaseOrder::query()->with(['supplier', 'lines'])->latest()->limit(50)->get()->map(fn (PurchaseOrder $o) => [
                'id' => $o->id, 'number' => $o->number, 'supplier' => $o->supplier->name, 'status' => $o->status, 'total' => $o->total_cents / 100, 'vat' => $o->vat_cents / 100,
                'lines' => $o->lines->map(fn ($l) => $l->only(['id', 'description', 'quantity', 'received_quantity', 'unit_cost_cents']))->values(),
            ])->values(),
            'reorder' => StockItem::query()->get()->filter(fn (StockItem $s) => $s->onHand() <= $s->reorder_level)->map(fn (StockItem $s) => ['id' => $s->id, 'medicine_id' => $s->medicine_id, 'name' => $s->description, 'onHand' => $s->onHand(), 'reorderLevel' => $s->reorder_level])->values(),
            'stock' => StockItem::query()->orderBy('name')->get()->map(fn (StockItem $s) => ['id' => $s->id, 'name' => $s->description, 'onHand' => $s->onHand()])->values(),
            'adjustments' => DB::table('stock_adjustments')->latest('id')->limit(30)->get(),
        ]);
    }

    public function act(Request $request, string $action, Procurement $procurement): RedirectResponse
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);
        /** @var User $user */
        $user = $request->user();
        $message = match ($action) {
            'supplier' => (function () use ($request): string {
                $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:20'], 'vat_number' => ['nullable', 'regex:/^4\d{9}$/']]);
                Supplier::create($data);

                return 'Supplier added.';
            })(),
            'order' => (function () use ($request, $procurement, $user): string {
                $data = $request->validate(['supplier_id' => ['required', 'integer'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.medicine_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'integer', 'min:1'], 'lines.*.unit_cost' => ['required', 'numeric', 'min:0']]);
                $lines = array_map(fn ($l) => ['medicine_id' => (int) $l['medicine_id'], 'quantity' => (int) $l['quantity'], 'unit_cost_cents' => (int) round(((float) $l['unit_cost']) * 100)], array_values($data['lines']));
                $po = $procurement->order(Supplier::query()->findOrFail((int) $data['supplier_id']), $lines, $user->id);

                return "Purchase order {$po->number} saved as a draft.";
            })(),
            'send' => 'Sent '.$procurement->send(PurchaseOrder::query()->findOrFail($request->integer('order_id')))->number.'.',
            'receive' => (function () use ($request, $procurement, $user): string {
                $data = $request->validate(['order_id' => ['required', 'integer'], 'receipts' => ['required', 'array', 'min:1'], 'receipts.*.line_id' => ['required', 'integer'], 'receipts.*.quantity' => ['required', 'integer', 'min:1'], 'receipts.*.batch' => ['required', 'string', 'max:40'], 'receipts.*.expiry' => ['required', 'date', 'after:today'], 'receipts.*.sell_price' => ['nullable', 'numeric', 'min:0']]);
                $receipts = array_map(fn ($r) => ['line_id' => (int) $r['line_id'], 'quantity' => (int) $r['quantity'], 'batch' => (string) $r['batch'], 'expiry' => (string) $r['expiry'], 'sell_price_cents' => isset($r['sell_price']) ? (int) round(((float) $r['sell_price']) * 100) : null], array_values($data['receipts']));
                $procurement->receive(PurchaseOrder::query()->findOrFail((int) $data['order_id']), $receipts, $user->id);

                return 'Stock received.';
            })(),
            'stock-take' => 'Adjusted by '.$procurement->stockTake(StockItem::query()->findOrFail($request->integer('stock_item_id')), $request->integer('counted'), $request->string('reason')->toString(), $user->id).'.',
            'write-off-expired' => $procurement->writeOffExpired($user->id).' expired batch(es) written off.',
            default => abort(404),
        };

        return back()->with('success', $message);
    }
}
