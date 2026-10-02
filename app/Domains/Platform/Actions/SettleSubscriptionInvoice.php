<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Gateways\GatewayFactory;
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
        if (! $invoice instanceof SubscriptionInvoice || ($result->amountCents !== null && $result->amountCents !== $invoice->total_cents)) {
            return false;
        }

        $this->settle($invoice, $gateway->value, $result->gatewayReference);

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
