<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Http\Controllers;

use App\Domains\Billing\Gateways\CheckoutRequest;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\BillingMandate;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Telemedicine\Support\Telemedicine;
use App\Domains\Wallet\Actions\StartTopup;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletTopup;
use App\Domains\Wallet\Models\WalletTransaction;
use App\Domains\Wallet\Support\WalletSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Provider owner: wallet balance, statement, top-ups, auto top-up.
 * Super admin: usage prices, default threshold and packs.
 */
class WalletController extends Controller
{
    public function show(): Response
    {
        $this->authorize(Permission::PAYMENTS_CONFIGURE);
        $wallet = Wallet::for($this->provider()->id);

        return Inertia::render('Settings/Wallet', [
            'wallet' => [
                'balance' => $wallet->balance_cents / 100, 'reserved' => $wallet->reserved_cents / 100, 'available' => $wallet->availableCents() / 100,
                'threshold' => $wallet->thresholdCents() / 100, 'acceptsOnline' => $wallet->acceptsOnlineBookings(),
                'autoTopup' => $wallet->auto_topup, 'autoTopupPack' => $wallet->auto_topup_pack_cents === null ? null : $wallet->auto_topup_pack_cents / 100,
            ],
            'packs' => array_map(fn (array $p) => ['amount' => $p['amount'] / 100, 'bonus' => $p['bonus'] / 100], WalletSettings::packs()),
            'prices' => ['video' => WalletSettings::priceCents('video') / 100, 'audio' => WalletSettings::priceCents('audio') / 100, 'chat' => WalletSettings::priceCents('chat') / 100],
            'savedCard' => BillingMandate::activeFor($this->provider()->id)?->label(),
            'telemedicine' => [
                'offered' => in_array('telemedicine', (array) (Subscription::query()->with('package')->where('tenant_id', $this->provider()->id)->latest('id')->first()?->package->addons ?? []), true),
                'enabled' => Telemedicine::enabledFor($this->provider()->id),
                'fee' => (int) config('clinicflow.telemedicine.addon_monthly_cents', 29900) / 100,
            ],
            'statement' => WalletTransaction::query()->where('wallet_id', $wallet->id)->latest('id')->limit(50)->get()
                ->map(fn (WalletTransaction $t) => ['at' => $t->created_at->format('j M H:i'), 'type' => $t->type, 'amount' => $t->amount_cents / 100, 'balance' => $t->balance_after_cents / 100, 'description' => $t->description])->values(),
        ]);
    }

    public function topup(Request $request, StartTopup $topups): HttpResponse
    {
        $this->authorize(Permission::PAYMENTS_CONFIGURE);
        $data = $request->validate(['amount' => ['required', 'numeric'], 'method' => ['required', Rule::in(['pay_link', 'saved_card'])]]);
        $topup = $topups->create(Wallet::for($this->provider()->id), (int) round(((float) $data['amount']) * 100), $data['method']);

        if ($data['method'] === 'saved_card') {
            return back()->with('success', $topups->chargeSavedCard($topup) ? 'Wallet topped up from your saved card.' : 'The saved card could not be charged. Use a pay link instead.');
        }

        return Inertia::location(rtrim((string) config('app.url'), '/')."/billing/topup/{$topup->checkout_token}");
    }

    public function autoTopup(Request $request): RedirectResponse
    {
        $this->authorize(Permission::PAYMENTS_CONFIGURE);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'amount' => ['nullable', 'numeric']]);
        $amount = isset($data['amount']) ? (int) round(((float) $data['amount']) * 100) : null;
        if ($data['enabled'] && ($amount === null || collect(WalletSettings::packs())->firstWhere('amount', $amount) === null)) {
            return back()->withErrors(['amount' => 'Choose the pack to buy automatically.']);
        }
        if ($data['enabled'] && BillingMandate::activeFor($this->provider()->id) === null) {
            return back()->withErrors(['enabled' => 'Auto top-up needs a saved card. Turn on automatic payment in Settings → Subscription first.']);
        }
        Wallet::for($this->provider()->id)->forceFill(['auto_topup' => (bool) $data['enabled'], 'auto_topup_pack_cents' => $data['enabled'] ? $amount : null])->save();

        return back()->with('success', $data['enabled'] ? 'Auto top-up is on.' : 'Auto top-up is off.');
    }

    /**
     * Central domain: pay a top-up through the platform's gateway.
     */
    public function pay(Request $request, string $token): HttpResponse
    {
        $topup = WalletTopup::query()->with('wallet.provider')->where('checkout_token', $token)->first();
        abort_unless($topup instanceof WalletTopup, 404);
        if ($topup->status === 'paid') {
            return response()->view('payments.done', ['message' => 'This top-up has already been paid. Thank you.']);
        }

        $config = GatewayFactory::platformDefault();
        abort_if($config === null, 503, 'Online payment is not available yet.');
        $root = $request->getSchemeAndHttpHost();

        $start = GatewayFactory::fromConfig($config)->startCheckout(new CheckoutRequest(
            amountCents: $topup->amount_cents + $topup->vat_cents,
            reference: $token,
            description: 'Clinic Flow telemedicine wallet top-up',
            returnUrl: "{$root}/billing/done",
            cancelUrl: "{$root}/billing/done?cancelled=1",
            notifyUrl: "{$root}/api/webhooks/platform/{$config->gateway->value}",
            email: 'billing@clinicflow.co.za',
            customerName: $topup->wallet->provider->name,
        ));
        $topup->forceFill(['gateway' => $config->gateway->value, 'gateway_reference' => $start->gatewayReference])->save();

        return $start->formAction !== null
            ? response()->view('payments.redirect', ['action' => $start->formAction, 'fields' => $start->formFields])
            : redirect()->away((string) $start->redirectUrl);
    }

    public function adminSettings(): Response
    {
        return Inertia::render('Admin/Wallet', [
            'prices' => [
                'video' => (int) WalletSettings::get('wallet.price_video_per_minute_cents') / 100,
                'audio' => (int) WalletSettings::get('wallet.price_audio_per_minute_cents') / 100,
                'chat' => (int) WalletSettings::get('wallet.price_chat_per_session_cents') / 100,
            ],
            'threshold' => WalletSettings::thresholdCents() / 100,
            'packs' => array_map(fn (array $p) => ['amount' => $p['amount'] / 100, 'bonus' => $p['bonus'] / 100], WalletSettings::packs()),
            'wallets' => Wallet::query()->with('provider')->orderBy('balance_cents')->limit(100)->get()->map(fn (Wallet $w) => [
                'provider' => $w->provider->name, 'balance' => $w->balance_cents / 100, 'reserved' => $w->reserved_cents / 100, 'below' => ! $w->acceptsOnlineBookings(),
            ])->values(),
        ]);
    }

    public function saveAdminSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'video' => ['required', 'numeric', 'min:0'], 'audio' => ['required', 'numeric', 'min:0'], 'chat' => ['required', 'numeric', 'min:0'],
            'threshold' => ['required', 'numeric', 'min:0'],
            'packs' => ['required', 'array', 'min:1', 'max:6'], 'packs.*.amount' => ['required', 'numeric', 'min:10'], 'packs.*.bonus' => ['required', 'numeric', 'min:0'],
        ]);
        $c = fn ($v) => (int) round(((float) $v) * 100);
        WalletSettings::put('wallet.price_video_per_minute_cents', $c($data['video']));
        WalletSettings::put('wallet.price_audio_per_minute_cents', $c($data['audio']));
        WalletSettings::put('wallet.price_chat_per_session_cents', $c($data['chat']));
        WalletSettings::put('wallet.threshold_cents', $c($data['threshold']));
        WalletSettings::put('wallet.packs', array_values(array_map(fn (array $p) => ['amount' => $c($p['amount']), 'bonus' => $c($p['bonus'])], $data['packs'])));
        activity('platform')->causedBy($request->user() instanceof User ? $request->user() : null)->log('Wallet pricing updated');

        return back()->with('success', 'Wallet pricing saved. New prices apply to consults booked from now on.');
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
