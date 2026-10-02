<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Http\Controllers;

use App\Domains\Clinical\Models\Consultation;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Pharmacy\Actions\ConfirmCollection;
use App\Domains\Pharmacy\Actions\DispensePrescription;
use App\Domains\Pharmacy\Actions\FulfilOwing;
use App\Domains\Pharmacy\Actions\QueryPrescription;
use App\Domains\Pharmacy\Actions\ReceiveStock;
use App\Domains\Pharmacy\Models\OwingItem;
use App\Domains\Pharmacy\Models\RegisterEntry;
use App\Domains\Pharmacy\Models\StockItem;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\PrescriptionItem;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * In-house pharmacy: dispensing queue, collection window, owing list, stock, S5/S6 register.
 */
class PharmacyController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);
        $visits = Visit::query()->with('patient')->whereIn('stage', [VisitStage::Pharmacy->value, VisitStage::Dispatch->value])->orderBy('stage_changed_at')->get();

        return Inertia::render('Pharmacy/Index', [
            'queue' => $visits->map(function (Visit $v): array {
                $script = Prescription::query()->with('items')->where('patient_id', $v->patient_id)->where('status', Prescription::SIGNED)
                    ->whereIn('consultation_id', Consultation::query()->where('visit_id', $v->id)->select('id'))->orderByDesc('version')->first();

                return [
                    'visitId' => $v->id, 'ticket' => $v->ticket, 'patient' => $v->patient->fullName(), 'stage' => $v->stage->value,
                    'script' => $script === null ? null : [
                        'id' => $script->id, 'version' => $script->version,
                        'items' => $script->items->map(fn (PrescriptionItem $i) => $i->only(['description', 'dose', 'quantity', 'repeats', 'schedule']))->values(),
                    ],
                ];
            })->values(),
            'owing' => OwingItem::query()->with('patient')->where('status', 'owing')->latest()->get()->map(fn (OwingItem $o) => [
                'id' => $o->id, 'patient' => $o->patient->fullName(), 'quantity' => $o->quantity,
                'item' => PrescriptionItem::query()->whereKey($o->prescription_item_id)->value('description'),
            ])->values(),
            'stock' => StockItem::query()->orderBy('description')->get()->map(fn (StockItem $s) => [
                'description' => $s->description, 'schedule' => $s->schedule, 'onHand' => $s->onHand(), 'reorder' => $s->onHand() <= $s->reorder_level,
            ])->values(),
            'register' => RegisterEntry::query()->latest('id')->limit(30)->get()->map(fn (RegisterEntry $r) => [
                'at' => $r->recorded_at->format('j M H:i'), 'schedule' => $r->schedule, 'movement' => $r->movement, 'quantity' => $r->quantity, 'balance' => $r->balance_after,
                'item' => StockItem::query()->whereKey($r->stock_item_id)->value('description'),
            ])->values(),
        ]);
    }

    public function dispense(Request $request, Visit $visit, DispensePrescription $action): RedirectResponse
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);
        $data = $request->validate(['prescription_id' => ['required', 'string']]);
        $result = $action->handle(Prescription::query()->findOrFail((string) $data['prescription_id']), $visit, $this->staff($request));

        return back()->with('success', "Dispensed. Collection code {$result['code']}".($result['owing'] > 0 ? " — {$result['owing']} item(s) owing." : '.'));
    }

    public function query(Request $request, Visit $visit, QueryPrescription $action): RedirectResponse
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);
        $data = $request->validate(['prescription_id' => ['required', 'string'], 'question' => ['required', 'string', 'max:500']]);
        $action->handle(Prescription::query()->findOrFail((string) $data['prescription_id']), $visit, $data['question'], $this->user($request));

        return back()->with('success', 'Sent back to the doctor.');
    }

    public function collect(Request $request, Visit $visit, ConfirmCollection $action): RedirectResponse
    {
        $this->authorize(Permission::VISITS_MANAGE);
        $data = $request->validate(['code' => ['required', 'digits:4'], 'collector_name' => ['nullable', 'string', 'max:120'], 'collector_id' => ['nullable', 'string', 'max:20']]);
        $action->handle($visit, $data['code'], $data['collector_name'] ?? null, $data['collector_id'] ?? null, $this->user($request));

        return back()->with('success', "{$visit->ticket} collected.");
    }

    public function receive(Request $request, ReceiveStock $action): RedirectResponse
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);
        $data = $request->validate([
            'medicine_id' => ['required', 'integer'], 'batch_number' => ['required', 'string', 'max:40'],
            'expiry_date' => ['required', 'date'], 'quantity' => ['required', 'integer', 'min:1'], 'unit_price' => ['required', 'numeric', 'min:0'],
        ]);
        $action->handle((int) $data['medicine_id'], $data['batch_number'], CarbonImmutable::parse($data['expiry_date']), (int) $data['quantity'], (int) round(((float) $data['unit_price']) * 100), $this->user($request)->id);

        return back()->with('success', 'Stock received.');
    }

    public function fulfil(Request $request, OwingItem $owing, FulfilOwing $action): RedirectResponse
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);
        $action->handle($owing, $this->staff($request));

        return back()->with('success', 'Owing item supplied.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function staff(Request $request): Staff
    {
        return Staff::query()->findOrFail($this->user($request)->id);
    }
}
