<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Messaging\Support\MessagingUsage;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Daily: invoice each subscription 3 days before its trial or period ends
 * (package price + VAT). Providers pay through the platform's gateway.
 */
class IssueSubscriptionInvoices
{
    public function handle(): int
    {
        $count = 0;

        Subscription::query()->with('package')
            ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::ReadOnly->value])
            ->each(function (Subscription $subscription) use (&$count): void {
                $periodStart = $subscription->current_period_ends_at ?? $subscription->trial_ends_at ?? now();
                $periodStart = Carbon::parse($periodStart)->startOfDay();

                if ($periodStart->gt(now()->addDays(3))) {
                    return;
                }

                $exists = SubscriptionInvoice::query()->where('subscription_id', $subscription->id)
                    ->whereDate('period_start', $periodStart)->whereIn('status', ['open', 'paid'])->exists();
                if ($exists) {
                    return;
                }

                $annual = $subscription->billing_period === 'annual';
                $amount = $annual ? $subscription->package->price_annual_cents : $subscription->package->price_monthly_cents;
                // Messaging above the package allowance in the month before this period.
                $usage = MessagingUsage::overage($subscription->tenant_id, $periodStart->copy()->subMonthNoOverflow()->format('Y-m'), $subscription->package);
                $amount += $usage['overage_cents'];
                if (in_array('telemedicine', (array) ($subscription->getAttribute('addons') ?? []), true)) {
                    $amount += (int) config('clinicflow.telemedicine.addon_monthly_cents', 29900) * ($annual ? 12 : 1);
                }
                $amount += (int) ($subscription->getAttribute('extra_branches') ?? 0) * (int) config('clinicflow.branches.extra_monthly_cents', 49900) * ($annual ? 12 : 1);
                $vat = (int) round($amount * (float) config('clinicflow.payments.vat_rate', 0.15));
                $year = now()->format('Y');
                $next = SubscriptionInvoice::query()->where('number', 'like', "CF-{$year}-%")->count() + 1;

                SubscriptionInvoice::create([
                    'number' => "CF-{$year}-".str_pad((string) $next, 6, '0', STR_PAD_LEFT),
                    'tenant_id' => $subscription->tenant_id,
                    'subscription_id' => $subscription->id,
                    'period_start' => $periodStart,
                    'period_end' => $annual ? $periodStart->copy()->addYear() : $periodStart->copy()->addMonth(),
                    'amount_cents' => $amount,
                    'messaging_units' => $usage['units'],
                    'messaging_overage_cents' => $usage['overage_cents'],
                    'vat_cents' => $vat,
                    'total_cents' => $amount + $vat,
                    'status' => 'open',
                    'checkout_token' => Str::random(48),
                    'due_at' => $periodStart,
                ]);
                $count++;
            });

        return $count;
    }
}
