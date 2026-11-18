<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;

/**
 * The banner across the top of a practice workspace (beta #10): suspended, read-only (with a Pay
 * now link), or a free trial ending within 7 days. Null when there is nothing to say.
 */
final class WorkspaceNotice
{
    /**
     * @return array{kind: string, text: string, action: string, href: string}|null
     */
    public static function for(Provider $provider): ?array
    {
        if ($provider->status === ProviderStatus::Suspended) {
            return ['kind' => 'danger', 'text' => 'Your workspace is read-only while a registration check is fixed.', 'action' => 'See what to fix', 'href' => '/settings/verification'];
        }
        $central = rtrim((string) config('app.url'), '/');
        $open = SubscriptionInvoice::query()->where('tenant_id', $provider->id)->where('status', 'open')->oldest('due_at')->first();
        $pay = $open !== null ? "{$central}/billing/pay/{$open->checkout_token}" : '/settings/subscription';
        if ($provider->status === ProviderStatus::ReadOnly) {
            return ['kind' => 'danger', 'text' => 'Your workspace is read-only because the subscription is unpaid. Your data is safe.', 'action' => 'Pay now', 'href' => $pay];
        }
        $subscription = Subscription::query()->where('tenant_id', $provider->id)->latest('id')->first();
        if ($subscription?->status === SubscriptionStatus::Trialing && $subscription->trial_ends_at !== null && $subscription->trial_ends_at->lte(now()->addDays(7))) {
            $days = max(0, (int) now()->startOfDay()->diffInDays($subscription->trial_ends_at->copy()->startOfDay(), false));
            $when = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in {$days} days");

            return ['kind' => 'warning', 'text' => "Your free trial ends {$when}.", 'action' => $open !== null ? 'Pay now' : 'View plan', 'href' => $pay];
        }
        if ($open !== null && $open->due_at->isPast()) {
            return ['kind' => 'warning', 'text' => "Invoice {$open->number} is overdue.", 'action' => 'Pay now', 'href' => $pay];
        }

        return null;
    }
}
