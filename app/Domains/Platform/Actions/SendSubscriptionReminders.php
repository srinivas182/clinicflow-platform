<?php

declare(strict_types=1);

namespace App\Domains\Platform\Actions;

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Platform\Support\PracticeContacts;
use Illuminate\Support\Facades\Notification;

/**
 * Daily (beta #10, #11): tells practice owners their free trial ends in 7, 3 or 1 days, and that an
 * invoice is due in 3 days. Each reminder is sent once.
 */
class SendSubscriptionReminders
{
    public const TRIAL_DAYS = [7, 3, 1];

    public function handle(): int
    {
        $sent = 0;
        Subscription::query()->with('package')->where('status', SubscriptionStatus::Trialing->value)->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', now())->where('trial_ends_at', '<=', now()->addDays(8))
            ->each(function (Subscription $s) use (&$sent): void {
                $days = (int) now()->startOfDay()->diffInDays($s->trial_ends_at?->copy()->startOfDay());
                $milestone = collect(self::TRIAL_DAYS)->first(fn (int $d) => $days <= $d && ($s->trial_reminded_days === null || $d < $s->trial_reminded_days));
                if ($milestone === null || $s->package->isFree()) {
                    return;
                }
                $provider = Provider::query()->find($s->tenant_id);
                if (! $provider instanceof Provider) {
                    return;
                }
                $when = $days <= 1 ? 'tomorrow' : "in {$days} days";
                Notification::send(PracticeContacts::owners($s->tenant_id), new PracticeNotice(
                    "Your Dr Business Flow trial ends {$when}",
                    ["The free trial for {$provider->name} ends on {$s->trial_ends_at?->toDateString()}.",
                        'Pay your first invoice to keep everything running. Unpaid workspaces become read-only 7 days after the trial ends; your data is never deleted.'],
                    'View plan and pay', PracticeContacts::url($provider, '/settings/subscription'), 'trial_ending',
                ));
                $s->forceFill(['trial_reminded_days' => $milestone])->save();
                $sent++;
            });

        SubscriptionInvoice::query()->where('status', 'open')->whereNull('reminded_at')
            ->whereDate('due_at', '<=', now()->addDays(3)->toDateString())->whereDate('due_at', '>=', now()->toDateString())
            ->where('created_at', '<', now()->subDay())
            ->each(function (SubscriptionInvoice $invoice) use (&$sent): void {
                $total = 'R'.number_format($invoice->total_cents / 100, 2, '.', ' ');
                Notification::send(PracticeContacts::owners($invoice->tenant_id), new PracticeNotice(
                    "Reminder: invoice {$invoice->number} is due on {$invoice->due_at->toDateString()}",
                    ["Invoice {$invoice->number} for {$total} (incl. VAT) is due on {$invoice->due_at->toDateString()}.", 'If automatic payment is on, there is nothing to do.'],
                    'View and pay', rtrim((string) config('app.url'), '/')."/billing/pay/{$invoice->checkout_token}", 'invoice_due',
                ));
                $invoice->forceFill(['reminded_at' => now()])->save();
                $sent++;
            });

        return $sent;
    }
}
