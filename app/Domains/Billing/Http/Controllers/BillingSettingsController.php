<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Enums\PaymentTiming;
use App\Domains\Billing\Enums\RefundRule;
use App\Domains\Billing\Support\BillingSettings;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Models\Setting;
use App\Domains\Visits\Actions\DeviceTokens;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Practice settings: payment timing, consult fee, refund rule, device links.
 */
class BillingSettingsController extends Controller
{
    public function edit(Request $request, DeviceTokens $tokens): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $devices = $tokens->ensure();
        $root = $request->getSchemeAndHttpHost();

        return Inertia::render('Settings/Billing', [
            'paymentTiming' => BillingSettings::paymentTiming()->value,
            'refundRule' => BillingSettings::refundRule()->value,
            'consultFee' => BillingSettings::consultFeeCents() / 100,
            'consultCode' => BillingSettings::consultCode(),
            'kioskUrl' => "{$root}/kiosk/{$devices['kiosk']}",
            'displayUrl' => "{$root}/display/{$devices['display']}",
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate([
            'payment_timing' => ['required', Rule::enum(PaymentTiming::class)],
            'refund_rule' => ['required', Rule::enum(RefundRule::class)],
            'consult_fee' => ['required', 'numeric', 'min:0', 'max:100000'],
            'consult_code' => ['required', 'string', 'max:12'],
        ]);

        Setting::put('billing', 'payment_timing', $data['payment_timing']);
        Setting::put('billing', 'refund_rule', $data['refund_rule']);
        Setting::put('billing', 'consult_fee_cents', (int) round(((float) $data['consult_fee']) * 100));
        Setting::put('billing', 'consult_code', $data['consult_code']);

        activity('settings')->causedBy($request->user())->withProperties($data)->log('Billing settings changed');

        return back()->with('success', 'Settings saved.');
    }
}
