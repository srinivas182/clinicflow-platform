<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Actions\SaveGatewayConfig;
use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\GatewayConfig;
use App\Domains\Billing\Models\PlatformGatewayConfig;
use App\Domains\Identity\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Payments: the provider connects its own merchant accounts.
 */
class PaymentSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permission::PAYMENTS_CONFIGURE);
        $offered = PlatformGatewayConfig::offeredGateways();
        $root = $request->getSchemeAndHttpHost();

        return Inertia::render('Settings/Payments', [
            'gateways' => array_values(array_map(fn (Gateway $g) => self::present($g, GatewayConfig::query()->where('gateway', $g->value)->first(), "{$root}/webhooks/payments/{$g->value}"),
                array_filter(Gateway::cases(), fn (Gateway $g) => in_array($g->value, $offered, true)))),
            'action' => '/settings/payments',
        ]);
    }

    public function update(Request $request, string $gateway, SaveGatewayConfig $action): RedirectResponse
    {
        $this->authorize(Permission::PAYMENTS_CONFIGURE);
        $g = Gateway::from($gateway);
        abort_unless(in_array($g->value, PlatformGatewayConfig::offeredGateways(), true), 403, 'This gateway is not offered on Clinic Flow.');

        $data = self::validated($request);
        $action->handle(GatewayConfig::class, $g, GatewayMode::from($data['mode']), (bool) $data['enabled'], (bool) ($data['is_default'] ?? false), $data['credentials'] ?? [], $request->user() instanceof User ? $request->user() : null);

        return back()->with('success', "{$g->label()} saved.");
    }

    public function test(string $gateway): RedirectResponse
    {
        $this->authorize(Permission::PAYMENTS_CONFIGURE);
        $config = GatewayConfig::query()->where('gateway', Gateway::from($gateway)->value)->firstOrFail();
        $result = GatewayFactory::fromConfig($config)->testConnection();
        $config->forceFill(['last_tested_at' => now(), 'last_test_ok' => $result->ok])->save();

        return back()->with($result->ok ? 'success' : 'error', $result->ok ? 'Connection works.' : ($result->error ?? 'Connection failed.'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Gateway $g, GatewayConfig|PlatformGatewayConfig|null $c, string $webhookUrl): array
    {
        $stored = $c === null ? [] : ($c->credentials ?? []);

        return [
            'gateway' => $g->value,
            'label' => $g->label(),
            'enabled' => $c !== null && $c->enabled,
            'isDefault' => $c !== null && $c->is_default,
            'mode' => $c?->mode->value ?? 'test',
            'offered' => $c instanceof PlatformGatewayConfig ? $c->offered_to_providers : true,
            'apiRefunds' => $g->supportsApiRefund(),
            'lastTestOk' => $c?->last_test_ok,
            'webhookUrl' => $webhookUrl,
            'fields' => array_map(fn (array $f) => [
                'key' => $f['key'], 'label' => $f['label'], 'secret' => $f['secret'],
                'value' => $f['secret'] ? '' : ($stored[$f['key']] ?? ''),
                'isSet' => ($stored[$f['key']] ?? '') !== '',
            ], $g->credentialFields()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function validated(Request $request): array
    {
        return $request->validate([
            'mode' => ['required', Rule::enum(GatewayMode::class)],
            'enabled' => ['required', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'offered_to_providers' => ['nullable', 'boolean'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
