<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Messaging\Support\MessageCatalogue;
use App\Domains\Messaging\WhatsApp\WhatsAppClient;
use App\Domains\Messaging\WhatsApp\WhatsAppProvider;
use App\Domains\Messaging\WhatsApp\WhatsAppRouter;
use App\Domains\Messaging\WhatsApp\WhatsAppTemplate;
use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Portal\Actions\PortalSignIn;
use App\Domains\Wallet\Support\WalletSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                    'sender' => $p?->sender, 'configured' => $p === null ? [] : array_keys(array_filter((array) ($p->credentials ?? [])))];
            })->values(),
            'templates' => WhatsAppTemplate::query()->orderBy('message_key')->get(['id', 'message_key', 'template_name', 'language', 'category', 'status', 'rejected_reason']),
            'messages' => collect(MessageCatalogue::all())->filter(fn (array $e) => in_array('sms', $e['channels'], true))->map(fn (array $e, string $k) => ['key' => $k, 'label' => $e['label'], 'category' => WhatsAppTemplate::categoryFor($e['category'])])->values(),
            'prices' => ['utility' => WhatsAppRouter::priceCents('utility') / 100, 'marketing' => WhatsAppRouter::priceCents('marketing') / 100, 'authentication' => WhatsAppRouter::priceCents('authentication') / 100],
        ]);
    }

    public function saveProvider(Request $request, string $driver): RedirectResponse
    {
        abort_unless(array_key_exists($driver, WhatsAppProvider::DRIVERS), 404);
        $fields = WhatsAppProvider::DRIVERS[$driver]['fields'];
        // Beta #241, #242: known key names only; sender is a number (Meta: the phone number ID); clearable.
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'sender' => ['nullable', 'string', 'max:40', $driver === 'meta' ? 'regex:/^\d{5,20}$/' : 'regex:/^\+?\d{9,15}$/'],
            'credentials' => ['array:'.implode(',', array_keys($fields))], 'credentials.*' => ['nullable', 'string', 'max:500'],
        ], ['sender.regex' => $driver === 'meta' ? 'The phone number ID is digits only.' : 'Enter the WhatsApp number, e.g. +27821234567.', 'credentials.array' => 'Unknown key name.']);
        $p = WhatsAppProvider::query()->firstOrNew(['driver' => $driver]);
        $merged = array_merge((array) ($p->credentials ?? []), array_filter((array) ($data['credentials'] ?? []), fn ($v) => filled($v)));
        $sender = $request->exists('sender') ? ($data['sender'] ?? null) : $p->sender;
        if ((bool) $data['enabled'] && (blank($sender) || array_diff(array_keys($fields), array_keys(array_filter($merged, fn ($v) => filled($v)))) !== [])) {
            throw ValidationException::withMessages(['credentials' => 'Enter every key and the sender before switching this supplier on.']);
        }
        $p->fill(['enabled' => (bool) $data['enabled'], 'sender' => $sender, 'credentials' => $merged])->save();
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
        // Per-message prices above R0 and at most R10 (beta #244).
        $data = $request->validate(['utility' => ['required', 'numeric', 'gt:0', 'max:10'], 'marketing' => ['required', 'numeric', 'gt:0', 'max:10'], 'authentication' => ['required', 'numeric', 'gt:0', 'max:10']]);
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

    /** How a patient can agree to WhatsApp messages, as recorded by staff (the portal records "portal"). */
    public const CONSENT_METHODS = ['in_person' => 'In person, verbally', 'signed_form' => 'Signed form', 'message' => 'By message (SMS or WhatsApp reply)', 'portal' => 'In the patient portal'];

    /** "Agreed in person, verbally on 2026-11-20 (recorded by Thandi Nkosi)" — shown to staff and the patient. */
    public static function consentRecord(Patient $patient): ?string
    {
        $at = $patient->getAttribute('whatsapp_opt_in_at');
        if ($at === null) {
            return null;
        }
        $method = self::CONSENT_METHODS[(string) $patient->getAttribute('whatsapp_opt_in_method')] ?? 'Method not recorded (before November 2026)';
        $by = $patient->getAttribute('whatsapp_opt_in_by');
        $name = $by === null ? null : User::query()->whereKey((int) $by)->value('name');

        return $method.' on '.Carbon::parse($at)->toDateString().($name !== null ? ' (recorded by '.$name.')' : '');
    }

    public function optIn(Request $request, Patient $patient): RedirectResponse
    {
        $this->authorize(Permission::PATIENTS_REGISTER);
        $on = $request->boolean('opt_in');
        // POPIA: how the patient agreed is recorded with who recorded it (beta #252).
        $method = $on ? (string) $request->validate(['method' => ['required', 'in:'.implode(',', array_keys(self::CONSENT_METHODS))]], [
            'method.required' => 'Choose how the patient agreed.',
        ])['method'] : null;
        // Opting in makes WhatsApp the patient's channel; withdrawing returns them to SMS.
        $patient->forceFill(['whatsapp_opt_in_at' => $on ? now() : null, 'whatsapp_opt_in_method' => $method, 'whatsapp_opt_in_by' => $on ? $request->user()?->getAuthIdentifier() : null,
            'preferred_channel' => $on ? Channel::WhatsApp : Channel::Sms])->save();
        activity('patients')->performedOn($patient)->causedBy($request->user())->withProperties(['method' => $method])->log($on ? 'WhatsApp opt-in recorded' : 'WhatsApp opt-in withdrawn');

        return back()->with('success', $on ? 'WhatsApp opt-in recorded.' : 'WhatsApp opt-in withdrawn.');
    }

    /**
     * The patient chooses WhatsApp (or stops it) themselves in the portal, for every profile on their cell number.
     */
    public function portalOptIn(Request $request, PortalSignIn $signIn): RedirectResponse
    {
        $on = $request->boolean('opt_in');
        foreach ($signIn->profiles((string) $request->session()->get('portal_cell')) as $patient) {
            /** @var Patient $patient */
            $patient->forceFill(['whatsapp_opt_in_at' => $on ? now() : null, 'whatsapp_opt_in_method' => $on ? 'portal' : null, 'whatsapp_opt_in_by' => null,
                'preferred_channel' => $on ? Channel::WhatsApp : Channel::Sms])->save();
            activity('portal')->performedOn($patient)->log($on ? 'Patient opted in to WhatsApp' : 'Patient stopped WhatsApp messages');
        }

        return back()->with('success', $on ? 'You will get messages on WhatsApp.' : 'You will get messages by SMS.');
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
