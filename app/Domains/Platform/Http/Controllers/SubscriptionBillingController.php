<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Billing\Gateways\CheckoutRequest;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
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

        return Inertia::render('Settings/Subscription', [
            'plan' => $subscription === null ? null : [
                'package' => $subscription->package->name,
                'status' => $subscription->status->value,
                'trialEndsAt' => $subscription->trial_ends_at?->toDateString(),
                'periodEndsAt' => $subscription->current_period_ends_at?->toDateString(),
            ],
            'invoices' => SubscriptionInvoice::query()->where('tenant_id', $provider->id)->latest()->get()->map(fn (SubscriptionInvoice $i) => [
                'number' => $i->number, 'period' => $i->period_start->toDateString().' – '.$i->period_end->toDateString(),
                'total' => $i->total_cents / 100, 'status' => $i->status, 'due' => $i->due_at->toDateString(),
                'payUrl' => $i->status === 'open' ? "{$central}/billing/pay/{$i->checkout_token}" : null,
            ])->values(),
        ]);
    }

    public function pay(Request $request, string $token): HttpResponse
    {
        $invoice = SubscriptionInvoice::query()->with('provider')->where('checkout_token', $token)->first();
        abort_unless($invoice instanceof SubscriptionInvoice, 404);

        if ($invoice->status !== 'open') {
            return response()->view('payments.done', ['message' => 'This invoice has already been paid. Thank you.']);
        }

        $config = GatewayFactory::platformDefault();
        abort_if($config === null, 503, 'Online payment is not available yet. Please pay by EFT using the invoice number as reference.');

        $owner = User::query()->whereIn('id', Membership::query()->where('tenant_id', $invoice->tenant_id)->where('role', 'owner')->pluck('user_id'))->first();
        $root = $request->getSchemeAndHttpHost();

        $start = GatewayFactory::fromConfig($config)->startCheckout(new CheckoutRequest(
            amountCents: $invoice->total_cents,
            reference: $token,
            description: "Clinic Flow subscription {$invoice->number}",
            returnUrl: "{$root}/billing/done",
            cancelUrl: "{$root}/billing/done?cancelled=1",
            notifyUrl: "{$root}/api/webhooks/platform/{$config->gateway->value}",
            email: $owner?->email ?? 'billing@clinicflow.co.za',
            customerName: $owner?->name ?? $invoice->provider->name,
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
