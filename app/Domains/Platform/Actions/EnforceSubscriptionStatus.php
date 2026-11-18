<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Subscription;

/**
 * Daily: trials that ended without payment, and unpaid periods past the grace
 * period, become read-only. Data is never deleted for non-payment.
 * Free packages are never made read-only: an ended "trial" on a free package simply becomes active.
 */
class EnforceSubscriptionStatus
{
    public const GRACE_DAYS = 7;

    public function handle(): int
    {
        $cutoff = now()->subDays(self::GRACE_DAYS);
        $count = 0;

        // Repairs free-package workspaces made read-only before free packages were exempt.
        Subscription::query()->with(['provider', 'package'])->where('status', SubscriptionStatus::ReadOnly->value)
            ->whereHas('package', fn ($q) => $q->where('price_monthly_cents', 0)->where('price_annual_cents', 0))
            ->each(fn (Subscription $s) => $this->activateFree($s));

        Subscription::query()
            ->with(['provider', 'package'])
            ->where(function ($q) use ($cutoff): void {
                $q->where(fn ($t) => $t->where('status', SubscriptionStatus::Trialing->value)->where('trial_ends_at', '<', $cutoff))
                    // A paid period that ended more than the grace period ago without the next invoice being paid.
                    ->orWhere(fn ($p) => $p->whereIn('status', [SubscriptionStatus::PastDue->value, SubscriptionStatus::Active->value])->where('current_period_ends_at', '<', $cutoff));
            })
            ->each(function (Subscription $subscription) use (&$count): void {
                if ($subscription->package->isFree()) {
                    $this->activateFree($subscription);

                    return;
                }
                $subscription->forceFill(['status' => SubscriptionStatus::ReadOnly])->save();
                $provider = $subscription->provider;
                $provider->status = ProviderStatus::ReadOnly;
                $provider->save();
                activity('platform')->performedOn($provider)->log('Provider set to read-only for non-payment');
                $count++;
            });

        return $count;
    }

    private function activateFree(Subscription $subscription): void
    {
        if (in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::ReadOnly], true)) {
            $subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
        }
        $provider = $subscription->provider;
        if (in_array($provider->status, [ProviderStatus::Trial, ProviderStatus::ReadOnly], true)) {
            $provider->status = ProviderStatus::Active;
            $provider->save();
            activity('platform')->performedOn($provider)->log('Free package: workspace active (no payment needed)');
        }
    }
}
