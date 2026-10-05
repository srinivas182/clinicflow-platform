<?php

declare(strict_types=1);

namespace App\Domains\Scribe\Http;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Scribe\Actions\AiScribe;
use App\Domains\Scribe\Models\AiProvider;
use App\Domains\Wallet\Support\WalletSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Practice: AI scribe add-on and usage. Super admin: providers and prices.
 */
class ScribeController extends Controller
{
    public function practice(AiScribe $scribe): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);
        $sub = Subscription::query()->with('package')->where('tenant_id', $provider->id)->latest('id')->first();

        return Inertia::render('Settings/AiScribe', [
            'offered' => in_array('ai_scribe', (array) ($sub?->package->addons ?? []), true),
            'on' => in_array('ai_scribe', (array) ($sub?->getAttribute('addons') ?? []), true),
            'allowance' => $scribe->allowance($provider->id),
            'prices' => ['monthly' => (int) WalletSettings::get('ai.addon_monthly_cents') / 100, 'minutes' => (int) WalletSettings::get('ai.addon_minutes'),
                'perMinute' => (int) WalletSettings::get('ai.price_per_minute_cents') / 100, 'maxMinutes' => (int) WalletSettings::get('ai.max_recording_minutes')],
            'available' => AiProvider::active('speech') !== null && AiProvider::active('notes') !== null,
        ]);
    }

    public function toggle(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);
        $sub = Subscription::query()->with('package')->where('tenant_id', $provider->id)->latest('id')->firstOrFail();
        abort_unless(in_array('ai_scribe', (array) ($sub->package->addons ?? []), true), 422, 'Your package does not offer the AI scribe.');
        $addons = array_values(array_diff((array) ($sub->getAttribute('addons') ?? []), ['ai_scribe']));
        if ($request->boolean('enabled')) {
            $addons[] = 'ai_scribe';
        }
        $sub->forceFill(['addons' => $addons])->save();
        activity('platform')->performedOn($provider)->withProperties(['ai_scribe' => $request->boolean('enabled')])->log('AI scribe add-on changed');

        return back()->with('success', $request->boolean('enabled') ? 'AI scribe is on. Included minutes are used first, then minutes are paid from your wallet.' : 'AI scribe is off.');
    }

    // ---------------- super admin ----------------

    public function admin(): Response
    {
        $central = DB::connection((string) config('tenancy.database.central_connection'));

        return Inertia::render('Admin/AiScribe', [
            'providers' => collect(AiProvider::DRIVERS)->map(function (string $kind, string $driver) {
                $p = AiProvider::query()->where('driver', $driver)->first();

                return ['driver' => $driver, 'kind' => $kind, 'enabled' => (bool) $p?->enabled, 'configured' => filled($p?->credential('api_key')),
                    'model' => $p?->credential('model') ?: null, 'region' => $p?->credential('region') ?: null, 'costPerMinute' => ($p === null ? 0 : $p->cost_per_minute_millicents) / 100000];
            })->values(),
            'prices' => ['monthly' => (int) WalletSettings::get('ai.addon_monthly_cents') / 100, 'minutes' => (int) WalletSettings::get('ai.addon_minutes'),
                'perMinute' => (int) WalletSettings::get('ai.price_per_minute_cents') / 100, 'maxMinutes' => (int) WalletSettings::get('ai.max_recording_minutes')],
            'usage' => $central->table('ai_usage')->where('period', now()->format('Y-m'))->get()->map(fn ($u) => [
                'practice' => (string) Provider::query()->whereKey($u->tenant_id)->value('name'), 'included' => (int) $u->minutes_included_used,
                'wallet' => (int) $u->minutes_wallet, 'walletRands' => $u->wallet_cents / 100,
            ])->values(),
        ]);
    }

    public function saveProvider(Request $request, string $driver): RedirectResponse
    {
        abort_unless(array_key_exists($driver, AiProvider::DRIVERS), 404);
        $data = $request->validate(['api_key' => ['nullable', 'string', 'max:300'], 'model' => ['nullable', 'string', 'max:60'], 'summary_model' => ['nullable', 'string', 'max:60'], 'region' => ['nullable', 'string', 'max:40'],
            'enabled' => ['boolean'], 'cost_per_minute' => ['nullable', 'numeric', 'min:0']]);
        $p = AiProvider::query()->firstOrNew(['driver' => $driver], ['kind' => AiProvider::DRIVERS[$driver]]);
        $creds = (array) ($p->credentials ?? []);
        foreach (['api_key', 'model', 'summary_model', 'region'] as $k) {
            if (filled($data[$k] ?? null)) {
                $creds[$k] = (string) $data[$k];
            }
        }
        $enable = (bool) ($data['enabled'] ?? false);
        if ($enable && ! filled($creds['api_key'] ?? null)) {
            return back()->withErrors(['api_key' => 'Enter the API key before enabling.']);
        }
        DB::connection((string) config('tenancy.database.central_connection'))->transaction(function () use ($p, $creds, $enable, $data, $driver): void {
            if ($enable) {
                // One active provider per kind.
                AiProvider::query()->where('kind', AiProvider::DRIVERS[$driver])->where('driver', '!=', $driver)->update(['enabled' => false]);
            }
            $p->forceFill(['kind' => AiProvider::DRIVERS[$driver], 'credentials' => $creds, 'enabled' => $enable,
                'cost_per_minute_millicents' => (int) round(((float) ($data['cost_per_minute'] ?? 0)) * 100000)])->save();
        });

        return back()->with('success', 'Provider saved.');
    }

    public function savePrices(Request $request): RedirectResponse
    {
        $data = $request->validate(['monthly' => ['required', 'numeric', 'min:0'], 'minutes' => ['required', 'integer', 'min:0'], 'per_minute' => ['required', 'numeric', 'min:0'],
            'max_minutes' => ['required', 'integer', Rule::in(range(5, 60))]]);
        WalletSettings::put('ai.addon_monthly_cents', (int) round((float) $data['monthly'] * 100));
        WalletSettings::put('ai.addon_minutes', (int) $data['minutes']);
        WalletSettings::put('ai.price_per_minute_cents', (int) round((float) $data['per_minute'] * 100));
        WalletSettings::put('ai.max_recording_minutes', (int) $data['max_minutes']);

        return back()->with('success', 'AI scribe prices saved.');
    }
}
