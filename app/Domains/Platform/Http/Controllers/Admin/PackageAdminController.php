<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Platform\Models\Package;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: package builder (prices, trial, limits, active).
 */
class PackageAdminController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Packages/Index', [
            'packages' => Package::query()->orderBy('sort_order')->get()->map(fn (Package $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'providerType' => $p->provider_type->label(),
                'priceMonthly' => $p->price_monthly_cents / 100,
                'trialDays' => $p->trial_days,
                'limits' => $p->limits,
                'features' => $p->features,
                'isActive' => $p->is_active,
            ])->values(),
        ]);
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $data = $request->validate([
            'price_monthly' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'is_active' => ['required', 'boolean'],
        ]);

        $cents = (int) round(((float) $data['price_monthly']) * 100);
        $package->forceFill([
            'price_monthly_cents' => $cents,
            'price_annual_cents' => (int) round($cents * 12 * 0.85),
            'trial_days' => (int) $data['trial_days'],
            'is_active' => (bool) $data['is_active'],
        ])->save();

        activity('platform')->causedBy($request->user())->performedOn($package)->log('Package updated');

        return back()->with('success', "{$package->name} updated. Existing providers move to the new price at their next billing date.");
    }
}
