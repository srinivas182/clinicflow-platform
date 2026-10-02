<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Subscription;

/**
 * Daily: trials that ended without payment, and unpaid periods past the grace
 * period, become read-only. Data is never deleted for non-payment.
 */
class EnforceSubscriptionStatus
{
    public const GRACE_DAYS = 7;

    public function handle(): int
    {
        $cutoff = now()->subDays(self::GRACE_DAYS);
        $count = 0;

        Subscription::query()
            ->with('provider')
            ->where(function ($q) use ($cutoff): void {
                $q->where(fn ($t) => $t->where('status', SubscriptionStatus::Trialing->value)->where('trial_ends_at', '<', $cutoff))
                    ->orWhere(fn ($p) => $p->where('status', SubscriptionStatus::PastDue->value)->where('current_period_ends_at', '<', $cutoff));
            })
            ->each(function (Subscription $subscription) use (&$count): void {
                $subscription->forceFill(['status' => SubscriptionStatus::ReadOnly])->save();
                $provider = $subscription->provider;
                $provider->status = ProviderStatus::ReadOnly;
                $provider->save();
                activity('platform')->performedOn($provider)->log('Provider set to read-only for non-payment');
                $count++;
            });

        return $count;
    }
}
