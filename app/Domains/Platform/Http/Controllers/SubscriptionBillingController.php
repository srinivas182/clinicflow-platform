<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Billing\Actions\RevokeBillingMandate;
use App\Domains\Billing\Gateways\CheckoutRequest;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\BillingMandate;
use App\Domains\Billing\Models\DebitAttempt;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\Actions\ChangePlan;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Provider side: see the plan and pay subscription invoices.
 * Payment runs on the central domain through the platform's gateway.
 */
class SubscriptionBillingController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::PAYMENTS_CONFIGURE);
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);
        $subscription = Subscription::query()->with('package')->where('tenant_id', $provider->id)->latest('id')->first();
        $central = rtrim((string) config('app.url'), '/');
        $mandate = BillingMandate::activeFor($provider->id);
        $platformGateway = GatewayFactory::platformDefault();

        return Inertia::render('Settings/Subscription', [
            'plan' => $subscription === null ? null : [
                'packageId' => $subscription->package_id,
                'billingPeriod' => $subscription->billing_period,
                'package' => $subscription->package->name,
                'status' => $subscription->status->value,
                'trialEndsAt' => $subscription->trial_ends_at?->toDateString(),
                'periodEndsAt' => $subscription->current_period_ends_at?->toDateString(),
            ],
            // Plans this kind of practice can move to (beta #9: upgrade from the free plan).
            'plans' => Package::query()->where('provider_type', $provider->type->value)->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (Package $p): array => ['id' => $p->id, 'name' => $p->name, 'summary' => $p->summary, 'monthly' => $p->price_monthly_cents / 100,
                    'annual' => $p->price_annual_cents / 100, 'free' => $p->isFree()])->values(),
            'invoices' => SubscriptionInvoice::query()->where('tenant_id', $provider->id)->latest()->get()->map(fn (SubscriptionInvoice $i) => [
                'number' => $i->number, 'period' => $i->period_start->toDateString().' – '.$i->period_end->toDateString(),
                'total' => $i->total_cents / 100, 'status' => $i->status, 'due' => $i->due_at->toDateString(),
                'payUrl' => $i->status === 'open' ? "{$central}/billing/pay/{$i->checkout_token}" : null,
                'lastAttempt' => DebitAttempt::query()->where('subscription_invoice_id', $i->id)->latest('attempted_at')->first()?->only(['outcome', 'message', 'attempted_at']),
            ])->values(),
            'autoDebit' => $mandate === null ? null : [
                'card' => $mandate->label(),
                'expiry' => $mandate->card_expiry,
                'gateway' => $mandate->gateway->label(),
                'since' => $mandate->consented_at->toDateString(),
                'chargedBy' => $mandate->gateway->chargesMandateItself() ? $mandate->gateway->label() : 'Dr Business Flow',
            ],
            'autoDebitAvailable' => $platformGateway !== null && $platformGateway->gateway->supportsAutoDebit(),
            'cardSaveAvailable' => $platformGateway !== null && $platformGateway->gateway->supportsAutoDebit() && ! $platformGateway->gateway->chargesMandateItself(),
            'platformGateway' => $platformGateway?->gateway->label(),
        ]);
    }

    /**
     * Owner agrees to save the card used for this invoice and pay future
     * invoices automatically, then continues to the gateway.
     */
    public function payWithAutoDebit(Request $request, string $number): HttpResponse
    {
        $this->authorize(Permission::PAYMENTS_CONFIGURE);
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);
        $request->validate(['consent' => ['accepted']]);

        $config = GatewayFactory::platformDefault();
        if ($config === null || ! $config->gateway->supportsAutoDebit()) {
            throw ValidationException::withMessages(['consent' => 'Automatic payment is not available with the current payment provider. Please pay each invoice.']);
        }

        $invoice = SubscriptionInvoice::query()->where('tenant_id', $provider->id)->where('number', $number)->where('status', 'open')->firstOrFail();
        $invoice->forceFill(['save_card' => true, 'save_card_consented_by' => $request->user()?->getAuthIdentifier(), 'save_card_consent_ip' => $request->ip()])->save();

        return Inertia::location(rtrim((string) config('app.url'), '/')."/billing/pay/{$invoice->checkout_token}");
    }

    /**
     * Save a card for automatic payment at any time, before any invoice (decision on beta rows 2
     * and 12). The owner consents; a R1 card check is charged and refunded straight away.
     */
    public function saveCard(Request $request): HttpResponse
    {
        $this->authorize(Permission::PAYMENTS_CONFIGURE);
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);
        $request->validate(['consent' => ['accepted']], ['consent.accepted' => 'Tick the box to agree to automatic payment.']);
        $config = GatewayFactory::platformDefault();
        if ($config === null || ! $config->gateway->supportsAutoDebit() || $config->gateway->chargesMandateItself()) {
            throw ValidationException::withMessages(['consent' => 'A card can be saved when you pay your first invoice with the current payment provider.']);
        }
        $token = Str::random(40);
        DB::connection((string) config('tenancy.database.central_connection'))->table('card_checks')->insert([
            'tenant_id' => $provider->id, 'checkout_token' => $token, 'amount_cents' => 100, 'status' => 'open',
            'consented_by' => $request->user()?->getAuthIdentifier(), 'consent_ip' => $request->ip(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        activity('platform')->causedBy($request->user())->performedOn($provider)->log('Card check started (save card for automatic payment)');

        return Inertia::location(rtrim((string) config('app.url'), '/')."/billing/card/{$token}");
    }

    /** Central: starts the R1 card check at the payment provider. */
    public function cardCheck(Request $request, string $token): HttpResponse
    {
        $check = DB::connection((string) config('tenancy.database.central_connection'))->table('card_checks')->where('checkout_token', $token)->first();
        abort_if($check === null, 404);
        if ($check->status !== 'open') {
            return response()->view('payments.done', ['title' => 'Card saved', 'message' => 'Your card is saved for automatic payment. The R1 check has been refunded.']);
        }
        $config = GatewayFactory::platformDefault();
        abort_if($config === null, 404);
        $owner = User::query()->whereIn('id', Membership::query()->where('tenant_id', $check->tenant_id)->where('role', 'owner')->pluck('user_id'))->first();
        $root = $request->getSchemeAndHttpHost();
        $start = GatewayFactory::fromConfig($config)->startCheckout(new CheckoutRequest(
            amountCents: (int) $check->amount_cents,
            reference: $token,
            description: 'Dr Business Flow card check (R1, refunded)',
            returnUrl: "{$root}/billing/done",
            cancelUrl: "{$root}/billing/done?cancelled=1",
            notifyUrl: "{$root}/api/webhooks/platform/{$config->gateway->value}",
            email: $owner instanceof User ? $owner->email : 'billing@clinicflow.co.za',
            customerName: $owner instanceof User ? $owner->name : '',
            saveCard: true,
        ));
        DB::connection((string) config('tenancy.database.central_connection'))->table('card_checks')->where('id', $check->id)
            ->update(['gateway' => $config->gateway->value, 'gateway_reference' => $start->gatewayReference, 'updated_at' => now()]);

        return $start->formAction !== null
            ? response()->view('payments.redirect', ['action' => $start->formAction, 'fields' => $start->formFields])
            : redirect()->away((string) $start->redirectUrl);
    }

    public function changePlan(Request $request, ChangePlan $action): RedirectResponse
    {
        $this->authorize(Permission::PAYMENTS_CONFIGURE);
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);
        $data = $request->validate(['package_id' => ['required', 'integer'], 'billing_period' => ['required', 'in:monthly,annual']]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $invoice = $action->handle($provider, (int) $data['package_id'], (string) $data['billing_period'], $user);

        return back()->with('success', $invoice === null ? 'Plan changed.' : "Plan changed. Invoice {$invoice->number} is ready to pay below.");
    }

    public function stopAutoDebit(Request $request, RevokeBillingMandate $action): RedirectResponse
    {
        $this->authorize(Permission::PAYMENTS_CONFIGURE);
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);
        $mandate = BillingMandate::activeFor($provider->id);
        abort_if($mandate === null, 404);

        $warning = $action->handle($mandate, $request->user() instanceof User ? $request->user() : null);

        return back()->with('success', $warning === null ? 'Automatic payment is off. Your card has been removed.' : "Automatic payment is off in Dr Business Flow. {$warning}");
    }

    public function pay(Request $request, string $token): HttpResponse
    {
        $invoice = SubscriptionInvoice::query()->with('provider')->where('checkout_token', $token)->first();
        abort_unless($invoice instanceof SubscriptionInvoice, 404);

        if ($invoice->status !== 'open') {
            return response()->view('payments.done', ['message' => 'This invoice has already been paid. Thank you.']);
        }

        $config = GatewayFactory::platformDefault();
        if ($config === null) {
            // No online payment yet: show how to pay by EFT (a normal page, not a "back shortly" error).
            return response()->view('payments.eft', [
                'title' => 'Pay by EFT',
                'intro' => 'Online card payment is not available yet. Please pay this invoice by EFT.',
                'reference' => $invoice->number,
                'amount' => 'R'.number_format($invoice->total_cents / 100, 2, '.', ' '),
                'eft' => (array) config('clinicflow.payments.eft'),
            ]);
        }

        $owner = User::query()->whereIn('id', Membership::query()->where('tenant_id', $invoice->tenant_id)->where('role', 'owner')->pluck('user_id'))->first();
        $root = $request->getSchemeAndHttpHost();

        $start = GatewayFactory::fromConfig($config)->startCheckout(new CheckoutRequest(
            amountCents: $invoice->total_cents,
            reference: $token,
            description: "Dr Business Flow subscription {$invoice->number}",
            returnUrl: "{$root}/billing/done",
            cancelUrl: "{$root}/billing/done?cancelled=1",
            notifyUrl: "{$root}/api/webhooks/platform/{$config->gateway->value}",
            email: $owner instanceof User ? $owner->email : 'billing@clinicflow.co.za',
            customerName: $owner instanceof User ? $owner->name : $invoice->provider->name,
            saveCard: $invoice->save_card && $config->gateway->supportsAutoDebit(),
            billingFrequency: $invoice->subscription->billing_period === 'annual' ? 'annual' : 'monthly',
        ));

        $invoice->forceFill(['gateway' => $config->gateway->value, 'gateway_reference' => $start->gatewayReference])->save();

        return $start->formAction !== null
            ? response()->view('payments.redirect', ['action' => $start->formAction, 'fields' => $start->formFields])
            : redirect()->away((string) $start->redirectUrl);
    }

    public function done(Request $request): HttpResponse
    {
        return response()->view('payments.done', ['message' => $request->boolean('cancelled')
            ? 'The payment was cancelled.'
            : 'Thank you. Your subscription payment is being confirmed.']);
    }
}
