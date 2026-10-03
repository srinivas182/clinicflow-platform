<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Messaging\Support\MessageCatalogue;
use App\Domains\Messaging\WhatsApp\WhatsAppClient;
use App\Domains\Messaging\WhatsApp\WhatsAppProvider;
use App\Domains\Messaging\WhatsApp\WhatsAppRouter;
use App\Domains\Messaging\WhatsApp\WhatsAppTemplate;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Wallet\Support\WalletSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WhatsApp: super admin supplier, templates and prices; practice add-on; patient opt-in.
 */
class WhatsAppController extends Controller
{
    public function admin(): Response
    {
        return Inertia::render('Admin/WhatsApp', [
            'drivers' => collect(WhatsAppProvider::DRIVERS)->map(function (array $d, string $key): array {
                $p = WhatsAppProvider::query()->where('driver', $key)->first();

                return ['driver' => $key, 'label' => $d['label'], 'fields' => $d['fields'], 'senderLabel' => $d['sender'], 'enabled' => (bool) $p?->enabled,
                    'sender' => $p?->sender, 'configured' => array_keys(array_filter((array) ($p?->credentials ?? [])))];
            })->values(),
            'templates' => WhatsAppTemplate::query()->orderBy('message_key')->get(['id', 'message_key', 'template_name', 'language', 'category', 'status', 'rejected_reason']),
            'messages' => collect(MessageCatalogue::all())->filter(fn (array $e) => in_array('sms', $e['channels'], true))->map(fn (array $e, string $k) => ['key' => $k, 'label' => $e['label'], 'category' => WhatsAppTemplate::categoryFor($e['category'])])->values(),
            'prices' => ['utility' => WhatsAppRouter::priceCents('utility') / 100, 'marketing' => WhatsAppRouter::priceCents('marketing') / 100, 'authentication' => WhatsAppRouter::priceCents('authentication') / 100],
        ]);
    }

    public function saveProvider(Request $request, string $driver): RedirectResponse
    {
        abort_unless(array_key_exists($driver, WhatsAppProvider::DRIVERS), 404);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'sender' => ['nullable', 'string', 'max:40'], 'credentials' => ['array'], 'credentials.*' => ['nullable', 'string', 'max:500']]);
        $p = WhatsAppProvider::query()->firstOrNew(['driver' => $driver]);
        $merged = array_merge((array) ($p->credentials ?? []), array_filter((array) ($data['credentials'] ?? []), fn ($v) => filled($v)));
        $p->fill(['enabled' => (bool) $data['enabled'], 'sender' => $data['sender'] ?? $p->sender, 'credentials' => $merged])->save();
        if ($p->enabled) {
            WhatsAppProvider::query()->whereKeyNot($p->id)->update(['enabled' => false]);
        }

        return back()->with('success', WhatsAppProvider::DRIVERS[$driver]['label'].' saved.');
    }

    public function saveTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'message_key' => ['required', 'string', Rule::in(array_keys(MessageCatalogue::all()))], 'template_name' => ['required', 'string', 'max:100'],
            'language' => ['required', 'string', 'max:8'], 'category' => ['required', Rule::in(['utility', 'marketing', 'authentication'])],
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
        ]);
        WhatsAppTemplate::query()->updateOrCreate(['message_key' => $data['message_key']], $data);

        return back()->with('success', 'Template saved.');
    }

    public function sync(WhatsAppClient $client): RedirectResponse
    {
        $p = WhatsAppProvider::query()->where('driver', 'meta')->first();
        abort_unless($p instanceof WhatsAppProvider, 422, 'Set up the Meta Cloud API first.');

        return back()->with('success', $client->syncMeta($p).' template status(es) updated from Meta.');
    }

    public function savePrices(Request $request): RedirectResponse
    {
        $data = $request->validate(['utility' => ['required', 'numeric', 'min:0'], 'marketing' => ['required', 'numeric', 'min:0'], 'authentication' => ['required', 'numeric', 'min:0']]);
        foreach ($data as $category => $rand) {
            WalletSettings::put("wallet.price_whatsapp_{$category}_cents", (int) round(((float) $rand) * 100));
        }

        return back()->with('success', 'WhatsApp prices saved.');
    }

    // ---------------- practice ----------------

    public function settings(): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $provider = $this->provider();
        $sub = Subscription::query()->with('package')->where('tenant_id', $provider->id)->latest('id')->first();

        return Inertia::render('Settings/WhatsApp', [
            'offered' => in_array('whatsapp', (array) ($sub?->package->addons ?? []), true),
            'active' => WhatsAppRouter::enabledFor($provider->id),
            'supplierReady' => WhatsAppProvider::active() !== null,
            'fee' => (int) config('clinicflow.whatsapp.addon_monthly_cents', 19900) / 100,
            'prices' => ['utility' => WhatsAppRouter::priceCents('utility') / 100, 'marketing' => WhatsAppRouter::priceCents('marketing') / 100],
            'optedIn' => Patient::query()->whereNotNull('whatsapp_opt_in_at')->count(),
        ]);
    }

    public function toggle(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $provider = $this->provider();
        $sub = Subscription::query()->with('package')->where('tenant_id', $provider->id)->latest('id')->firstOrFail();
        abort_unless(in_array('whatsapp', (array) ($sub->package->addons ?? []), true), 422, 'Your package does not offer WhatsApp.');
        $addons = array_values(array_diff((array) ($sub->getAttribute('addons') ?? []), ['whatsapp']));
        if ($request->boolean('enabled')) {
            $addons[] = 'whatsapp';
        }
        $sub->forceFill(['addons' => $addons])->save();
        activity('platform')->performedOn($provider)->withProperties(['whatsapp' => $request->boolean('enabled')])->log('WhatsApp add-on changed');

        return back()->with('success', $request->boolean('enabled') ? 'WhatsApp is on. Messages are paid from your wallet.' : 'WhatsApp is off. Messages go by SMS.');
    }

    public function optIn(Request $request, Patient $patient): RedirectResponse
    {
        $this->authorize(Permission::PATIENTS_REGISTER);
        $on = $request->boolean('opt_in');
        $patient->forceFill(['whatsapp_opt_in_at' => $on ? now() : null])->save();
        activity('patients')->performedOn($patient)->causedBy($request->user())->log($on ? 'WhatsApp opt-in recorded' : 'WhatsApp opt-in withdrawn');

        return back()->with('success', $on ? 'WhatsApp opt-in recorded.' : 'WhatsApp opt-in withdrawn.');
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
