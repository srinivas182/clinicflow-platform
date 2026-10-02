<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Billing\Actions\SaveBillingMandate;
use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\BillingMandate;
use App\Domains\Billing\Models\DebitAttempt;
use App\Domains\Billing\Models\PlatformGatewayConfig;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\SubscriptionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform webhook: a provider paid its subscription invoice. The subscription
 * becomes active for the paid period and a read-only provider is reopened.
 */
class SettleSubscriptionInvoice
{
    public function fromWebhook(Gateway $gateway, Request $request): bool
    {
        $config = PlatformGatewayConfig::query()->where('gateway', $gateway->value)->first();
        if (! $config instanceof PlatformGatewayConfig) {
            return false;
        }

        $result = GatewayFactory::fromConfig($config)->handleWebhook($request);
        if ($result === null || ! $result->paid) {
            return false;
        }

        $invoice = SubscriptionInvoice::query()->where('checkout_token', $result->reference)->first();

        // A later charge on a saved card (PayFast runs the subscription itself):
        // settle the provider's oldest open invoice for the same amount.
        if ((! $invoice instanceof SubscriptionInvoice || $invoice->status === 'paid') && $result->mandate !== null) {
            return $this->settleRecurring($gateway, $result->mandate->token, $result->amountCents, $result->gatewayReference);
        }

        if (! $invoice instanceof SubscriptionInvoice || ($result->amountCents !== null && $result->amountCents !== $invoice->total_cents)) {
            return false;
        }

        $this->settle($invoice, $gateway->value, $result->gatewayReference);

        if ($invoice->save_card && $result->mandate !== null && $gateway->supportsAutoDebit()) {
            app(SaveBillingMandate::class)->handle($invoice, $gateway, $config->mode, $result->mandate);
        }

        return true;
    }

    private function settleRecurring(Gateway $gateway, string $token, ?int $amountCents, ?string $gatewayReference): bool
    {
        $mandate = BillingMandate::query()->where('token_hash', BillingMandate::hashToken($token))
            ->where('gateway', $gateway->value)->where('status', BillingMandate::ACTIVE)->first();
        if (! $mandate instanceof BillingMandate) {
            return false;
        }

        $reference = $gateway->value.'-'.($gatewayReference ?? '');
        if ($gatewayReference !== null && DebitAttempt::query()->where('reference', $reference)->exists()) {
            return true; // repeated notification
        }

        $invoice = SubscriptionInvoice::query()->where('tenant_id', $mandate->tenant_id)->where('status', 'open')
            ->when($amountCents !== null, fn ($q) => $q->where('total_cents', $amountCents))
            ->orderBy('due_at')->first();
        if (! $invoice instanceof SubscriptionInvoice) {
            activity('platform')->performedOn($mandate->provider)->withProperties(['gateway' => $gateway->value, 'amount_cents' => $amountCents])
                ->log('Automatic payment received with no matching open invoice');

            return false;
        }

        DebitAttempt::create([
            'subscription_invoice_id' => $invoice->id,
            'billing_mandate_id' => $mandate->id,
            'reference' => $reference,
            'outcome' => 'paid',
            'attempted_at' => now(),
        ]);
        $this->settle($invoice, $gateway->value, $gatewayReference);
        $mandate->forceFill(['failure_count' => 0, 'last_charged_at' => now()])->save();

        return true;
    }

    public function settle(SubscriptionInvoice $invoice, string $gateway, ?string $gatewayReference): void
    {
        DB::transaction(function () use ($invoice, $gateway, $gatewayReference): void {
            $locked = SubscriptionInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status === 'paid') {
                return;
            }

            $locked->forceFill(['status' => 'paid', 'paid_at' => now(), 'gateway' => $gateway, 'gateway_reference' => $gatewayReference])->save();

            $subscription = $locked->subscription;
            $subscription->forceFill(['status' => SubscriptionStatus::Active, 'current_period_ends_at' => $locked->period_end->endOfDay()])->save();

            $provider = $locked->provider;
            if (in_array($provider->status, [ProviderStatus::Trial, ProviderStatus::ReadOnly], true)) {
                $provider->status = ProviderStatus::Active;
                $provider->save();
            }

            activity('platform')->performedOn($provider)->withProperties(['invoice' => $locked->number])->log('Subscription paid');
        });
    }
}
