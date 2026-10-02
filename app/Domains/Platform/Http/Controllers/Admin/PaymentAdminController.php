<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Billing\Actions\SaveGatewayConfig;
use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Http\Controllers\PaymentSettingsController;
use App\Domains\Billing\Models\PlatformGatewayConfig;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: the platform's own gateway accounts (subscription billing) and
 * which gateways providers may connect.
 */
class PaymentAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $root = $request->getSchemeAndHttpHost();

        return Inertia::render('Admin/Payments', [
            'gateways' => array_map(fn (Gateway $g) => PaymentSettingsController::present($g, PlatformGatewayConfig::query()->where('gateway', $g->value)->first(), "{$root}/api/webhooks/platform/{$g->value}"), Gateway::cases()),
            'invoices' => SubscriptionInvoice::query()->with('provider')->latest()->limit(20)->get()->map(fn (SubscriptionInvoice $i) => [
                'number' => $i->number, 'provider' => $i->provider->name, 'total' => $i->total_cents / 100,
                'status' => $i->status, 'gateway' => $i->gateway, 'due' => $i->due_at->toDateString(),
            ])->values(),
        ]);
    }

    public function update(Request $request, string $gateway, SaveGatewayConfig $action): RedirectResponse
    {
        $g = Gateway::from($gateway);
        $data = PaymentSettingsController::validated($request);
        $action->handle(PlatformGatewayConfig::class, $g, GatewayMode::from($data['mode']), (bool) $data['enabled'], (bool) ($data['is_default'] ?? false),
            $data['credentials'] ?? [], $request->user() instanceof User ? $request->user() : null,
            ['offered_to_providers' => (bool) ($data['offered_to_providers'] ?? true)]);

        return back()->with('success', "{$g->label()} saved.");
    }

    public function test(string $gateway): RedirectResponse
    {
        $config = PlatformGatewayConfig::query()->where('gateway', Gateway::from($gateway)->value)->firstOrFail();
        $result = GatewayFactory::fromConfig($config)->testConnection();
        $config->forceFill(['last_tested_at' => now(), 'last_test_ok' => $result->ok])->save();

        return back()->with($result->ok ? 'success' : 'error', $result->ok ? 'Connection works.' : ($result->error ?? 'Connection failed.'));
    }
}
