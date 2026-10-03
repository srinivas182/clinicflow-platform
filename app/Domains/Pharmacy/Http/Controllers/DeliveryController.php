<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Http\Controllers;

use App\Domains\Hub\Actions\PharmacyComparison;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Pharmacy\Delivery\CourierAccount;
use App\Domains\Pharmacy\Delivery\CourierPartner;
use App\Domains\Pharmacy\Delivery\Deliveries;
use App\Domains\Pharmacy\Delivery\Delivery;
use App\Domains\Platform\Models\Setting;
use App\Domains\Portal\Actions\PortalSignIn;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\PrescriptionItem;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deliveries (practice), courier partners (super admin) and pharmacy comparison.
 */
class DeliveryController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);

        return Inertia::render('Pharmacy/Deliveries', [
            'deliveries' => Delivery::query()->with('patient')->latest()->limit(100)->get()->map(fn (Delivery $d) => [
                'id' => $d->id, 'patient' => $d->patient->fullName(), 'driver' => $d->driver, 'address' => $d->address, 'status' => $d->status,
                'tracking' => $d->tracking_number, 'fee' => $d->fee_cents / 100, 'payer' => $d->payer, 'failure' => $d->failure_reason,
            ])->values(),
            'couriers' => Deliveries::available(),
            'partners' => CourierPartner::query()->where('enabled', true)->get()->map(fn (CourierPartner $p) => ['driver' => $p->driver, 'label' => CourierPartner::LABELS[$p->driver] ?? $p->driver, 'apiReady' => $p->api_ready,
                'linked' => CourierAccount::query()->where('driver', $p->driver)->where('enabled', true)->exists()])->values(),
            'rules' => [...Deliveries::rules(), 'fee' => Deliveries::rules()['fee_cents'] / 100, 'threshold' => Deliveries::rules()['threshold_cents'] / 100, 'publish_stock' => (bool) Setting::get('pharmacy', 'publish_stock', false)],
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['patient', 'practice', 'threshold'])], 'fee' => ['required', 'numeric', 'min:0'], 'threshold' => ['required', 'numeric', 'min:0'],
            'below_payer' => ['required', Rule::in(['patient', 'practice'])], 'above_payer' => ['required', Rule::in(['patient', 'practice'])],
            'allow_scheduled' => ['required', 'boolean'], 'publish_stock' => ['required', 'boolean'],
        ]);
        foreach (['mode', 'below_payer', 'above_payer', 'allow_scheduled'] as $k) {
            Setting::put('delivery', $k, $data[$k]);
        }
        Setting::put('delivery', 'fee_cents', (int) round(((float) $data['fee']) * 100));
        Setting::put('delivery', 'threshold_cents', (int) round(((float) $data['threshold']) * 100));
        Setting::put('pharmacy', 'publish_stock', (bool) $data['publish_stock']);

        return back()->with('success', 'Delivery settings saved.');
    }

    public function link(Request $request, string $driver): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        abort_unless(CourierPartner::query()->where('driver', $driver)->where('enabled', true)->exists(), 404);
        $data = $request->validate(['account_ref' => ['required', 'string', 'max:80'], 'api_key' => ['nullable', 'string', 'max:500'], 'enabled' => ['required', 'boolean']]);
        CourierAccount::query()->updateOrCreate(['driver' => $driver], ['account_ref' => $data['account_ref'], 'enabled' => (bool) $data['enabled'], 'credentials' => array_filter(['api_key' => $data['api_key'] ?? null])]);

        return back()->with('success', (CourierPartner::LABELS[$driver] ?? $driver).' account saved.');
    }

    public function request(Request $request, Visit $visit, Deliveries $deliveries): RedirectResponse
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);
        $deliveries->request($visit, $request->string('driver')->toString(), $request->string('address')->toString());

        return back()->with('success', 'Delivery requested. The patient has the proof-of-delivery code.');
    }

    public function act(Request $request, Delivery $delivery, string $action, Deliveries $deliveries): RedirectResponse
    {
        $this->authorize(Permission::PHARMACY_DISPENSE);
        match ($action) {
            'book' => $deliveries->book($delivery, $request->string('tracking_number')->toString()),
            'status' => $deliveries->update($delivery, $request->string('status')->toString(), $request->string('reason')->toString() ?: null),
            'confirm' => $deliveries->confirm($delivery, $request->string('code')->toString()),
            default => abort(404),
        };

        return back()->with('success', 'Delivery updated.');
    }

    // ---------------- super admin ----------------

    public function adminPartners(): Response
    {
        return Inertia::render('Admin/Couriers', [
            'partners' => collect(CourierPartner::LABELS)->map(fn (string $label, string $driver) => ['driver' => $driver, 'label' => $label,
                'enabled' => (bool) CourierPartner::query()->where('driver', $driver)->value('enabled'), 'apiReady' => (bool) CourierPartner::query()->where('driver', $driver)->value('api_ready')])->values(),
        ]);
    }

    public function savePartner(Request $request, string $driver): RedirectResponse
    {
        abort_unless(array_key_exists($driver, CourierPartner::LABELS), 404);
        CourierPartner::query()->updateOrCreate(['driver' => $driver], ['enabled' => $request->boolean('enabled'), 'api_ready' => $request->boolean('api_ready')]);

        return back()->with('success', CourierPartner::LABELS[$driver].' saved.');
    }

    // ---------------- comparison ----------------

    public function compare(Prescription $prescription, PharmacyComparison $comparison): JsonResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);

        return response()->json($comparison->compare($this->items($prescription)));
    }

    public function portalCompare(Request $request, PortalSignIn $signIn, PharmacyComparison $comparison): Response
    {
        $ids = $signIn->profiles((string) $request->session()->get('portal_cell'))->pluck('id');
        $script = Prescription::query()->whereIn('patient_id', $ids)->where('status', Prescription::SIGNED)->latest('signed_at')->first();

        return Inertia::render('Portal/Pharmacies', [
            'items' => $script === null ? [] : $script->items()->get()->map(fn (PrescriptionItem $i) => ['description' => $i->description, 'quantity' => $i->quantity])->values(),
            'pharmacies' => $script === null ? [] : array_map(fn (array $r) => [...$r, 'estimate' => $r['estimate_cents'] === null ? null : $r['estimate_cents'] / 100], $comparison->compare($this->items($script))),
        ]);
    }

    /**
     * @return list<array{nappi_code: string, quantity: int, description: string}>
     */
    private function items(Prescription $prescription): array
    {
        return array_values($prescription->items()->get()->map(fn (PrescriptionItem $i) => ['nappi_code' => (string) $i->nappi_code, 'quantity' => (int) $i->quantity, 'description' => $i->description])->all());
    }
}
