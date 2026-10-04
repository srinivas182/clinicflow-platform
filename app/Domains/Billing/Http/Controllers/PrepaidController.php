<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Models\InvoiceLine;
use App\Domains\Billing\Prepaid\PrepaidPackages;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Patients\Models\Patient;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Prepaid packages: define, sell, and use against invoice lines.
 */
class PrepaidController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::BILLING_COLLECT);

        return Inertia::render('Billing/Packages', [
            'packages' => DB::table('prepaid_packages')->orderBy('name')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'description' => $p->description,
                'price' => $p->price_cents / 100, 'items' => json_decode((string) $p->items, true), 'active' => (bool) $p->active])->values(),
            'sold' => DB::table('patient_packages')->join('patients', 'patients.id', '=', 'patient_packages.patient_id')->join('prepaid_packages', 'prepaid_packages.id', '=', 'patient_packages.prepaid_package_id')
                ->orderByDesc('patient_packages.created_at')->limit(100)->get(['patient_packages.*', 'patients.first_names', 'patients.surname', 'prepaid_packages.name as package'])
                ->map(fn ($r) => ['id' => $r->id, 'patient' => "{$r->first_names} {$r->surname}", 'package' => $r->package, 'status' => $r->status,
                    'remaining' => json_decode((string) $r->remaining, true), 'expires' => $r->expires_at === null ? null : substr((string) $r->expires_at, 0, 10)])->values(),
            'validYears' => PrepaidPackages::VALID_YEARS,
        ]);
    }

    public function save(Request $request, PrepaidPackages $packages): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate([
            'id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'price' => ['required', 'numeric', 'min:1'],
            'items' => ['required', 'array'], 'items.*.service' => ['required', 'string'], 'items.*.label' => ['required', 'string', 'max:80'], 'items.*.quantity' => ['required', 'integer'], 'active' => ['boolean'],
        ]);
        $items = array_values(array_map(fn ($i) => ['service' => strtolower((string) $i['service']) === 'consultation' ? 'consultation' : (string) preg_replace_callback('/:(.*)$/', fn ($m) => ':'.strtoupper($m[1]), strtolower((string) $i['service'])), 'label' => (string) $i['label'], 'quantity' => (int) $i['quantity']], (array) $data['items']));
        $packages->savePackage(isset($data['id']) ? (int) $data['id'] : null, $data['name'], $data['description'] ?? null, (int) round(((float) $data['price']) * 100), $items, (bool) ($data['active'] ?? true));

        return back()->with('success', 'Package saved.');
    }

    public function sell(Request $request, Patient $patient, PrepaidPackages $packages): RedirectResponse
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $invoiceId = $packages->sell($patient, $request->integer('package_id'));

        return redirect("/invoices/{$invoiceId}")->with('success', 'Take payment to activate the package.');
    }

    public function redeem(Request $request, InvoiceLine $line, PrepaidPackages $packages): RedirectResponse
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $packages->redeem($request->string('patient_package_id')->toString(), $line, (int) $request->user()?->getAuthIdentifier());

        return back()->with('success', 'Package used for this line.');
    }
}
