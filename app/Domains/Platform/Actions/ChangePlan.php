<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Platform\Support\PracticeContacts;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The practice owner changes plan (beta #9, practice tab row 4). The new plan applies at once.
 * - During a trial: the trial simply continues on the new plan.
 * - To a free plan: active straight away, nothing to pay; unpaid invoices for the old plan are voided.
 * - From a free plan to a paid one: an invoice is issued now and is due now (usual 7-day grace).
 * - Between paid plans: an unpaid invoice is re-issued at the new price; otherwise the next invoice
 *   uses the new price (no part-period charges).
 */
class ChangePlan
{
    public function __construct(private readonly IssueSubscriptionInvoices $invoices) {}

    public function handle(Provider $provider, int $packageId, string $period, User $by): ?SubscriptionInvoice
    {
        $provider = Provider::query()->findOrFail($provider->id); // current status, not the request's copy
        $subscription = Subscription::query()->with('package')->where('tenant_id', $provider->id)->latest('id')->first();
        $package = Package::query()->whereKey($packageId)->where('is_active', true)->first();
        if ($subscription === null || ! $package instanceof Package || $package->provider_type !== $provider->type) {
            throw ValidationException::withMessages(['package' => 'Choose one of the plans offered for your kind of practice.']);
        }
        $period = $period === 'annual' ? 'annual' : 'monthly';
        if ($package->id === $subscription->package_id && $period === $subscription->billing_period) {
            throw ValidationException::withMessages(['package' => 'You are already on this plan.']);
        }
        $from = $subscription->package;

        $issued = DB::connection((string) config('tenancy.database.central_connection'))->transaction(function () use ($subscription, $package, $period, $from, $provider): ?SubscriptionInvoice {
            $central = DB::connection((string) config('tenancy.database.central_connection'));
            $open = SubscriptionInvoice::query()->where('subscription_id', $subscription->id)->where('status', 'open')->pluck('id');
            if ($open->isNotEmpty()) {
                // Unpaid invoices were for the old plan; any locum fees on them are billed again.
                SubscriptionInvoice::query()->whereIn('id', $open)->update(['status' => 'void']);
                $central->table('locum_fees')->whereIn('subscription_invoice_id', $open)->update(['subscription_invoice_id' => null]);
            }
            $subscription->forceFill(['package_id' => $package->id, 'billing_period' => $period]);

            if ($package->isFree()) {
                $subscription->forceFill(['status' => SubscriptionStatus::Active, 'trial_ends_at' => null, 'current_period_ends_at' => null])->save();
                if (in_array($provider->status, [ProviderStatus::Trial, ProviderStatus::ReadOnly], true)) {
                    $provider->status = ProviderStatus::Active;
                    $provider->save();
                }

                return null;
            }
            if ($subscription->status !== SubscriptionStatus::Trialing && ($from->isFree() || $subscription->current_period_ends_at === null)) {
                // Leaving a free plan: the first paid period starts now.
                $subscription->forceFill(['status' => SubscriptionStatus::Active, 'trial_ends_at' => null, 'current_period_ends_at' => now()]);
            }
            $subscription->save();
            $subscription->setRelation('package', $package);

            return $this->invoices->issueFor($subscription);
        });

        activity('platform')->causedBy($by)->performedOn($provider)->withProperties(['from' => $from->code, 'to' => $package->code, 'period' => $period])->log('Plan changed');
        Notification::send(PracticeContacts::owners($provider->id), new PracticeNotice(
            "Your plan is now {$package->name}",
            array_values(array_filter([
                "{$provider->name} moved from {$from->name} to {$package->name} ({$period}).",
                $package->isFree() ? 'This plan is free: there is nothing to pay.' : null,
                $issued !== null ? "Invoice {$issued->number} has been issued for the new plan." : null,
            ])),
            'View subscription', PracticeContacts::url($provider, '/settings/subscription'), 'plan_changed',
        ));

        return $issued;
    }
}
