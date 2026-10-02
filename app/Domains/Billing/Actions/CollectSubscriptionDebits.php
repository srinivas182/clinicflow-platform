<?php

declare(strict_types=1);

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Contracts\RecurringPaymentGateway;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\BillingMandate;
use App\Domains\Billing\Models\DebitAttempt;
use App\Domains\Billing\Models\PlatformGatewayConfig;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Notifications\SubscriptionDebitNotice;
use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\Actions\SettleSubscriptionInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Daily auto-debit run for subscription invoices that are due.
 * Paystack and Peach cards are charged here; PayFast charges itself.
 * Up to three attempts, at least two days apart; the owner is emailed
 * after every attempt.
 */
class CollectSubscriptionDebits
{
    public const MAX_ATTEMPTS = 3;

    public const RETRY_AFTER_DAYS = 2;

    public function __construct(private readonly SettleSubscriptionInvoice $settle) {}

    /**
     * @return array{paid: int, failed: int}
     */
    public function handle(): array
    {
        $paid = 0;
        $failed = 0;

        SubscriptionInvoice::query()->with('provider')->where('status', 'open')->where('due_at', '<=', now())
            ->each(function (SubscriptionInvoice $invoice) use (&$paid, &$failed): void {
                $mandate = BillingMandate::activeFor($invoice->tenant_id);
                if (! $mandate instanceof BillingMandate || $mandate->gateway->chargesMandateItself() || ! $mandate->gateway->supportsAutoDebit()) {
                    return;
                }

                $attempts = DebitAttempt::query()->where('subscription_invoice_id', $invoice->id)->orderByDesc('attempted_at')->get();
                $last = $attempts->first();
                if ($attempts->count() >= self::MAX_ATTEMPTS || ($last !== null && $last->attempted_at->gt(now()->subDays(self::RETRY_AFTER_DAYS)))) {
                    return;
                }

                $config = PlatformGatewayConfig::query()->where('gateway', $mandate->gateway->value)->where('enabled', true)->first();
                $gateway = $config instanceof PlatformGatewayConfig ? GatewayFactory::fromConfig($config) : null;
                if (! $gateway instanceof RecurringPaymentGateway) {
                    return;
                }

                $reference = $invoice->checkout_token.'-'.($attempts->count() + 1);
                $result = $gateway->chargeMandate($mandate->token, (string) $mandate->email, $invoice->total_cents, $reference);

                DebitAttempt::create([
                    'subscription_invoice_id' => $invoice->id,
                    'billing_mandate_id' => $mandate->id,
                    'reference' => $reference,
                    'outcome' => $result->ok ? 'paid' : 'failed',
                    'message' => $result->error,
                    'attempted_at' => now(),
                ]);

                $amount = 'R'.number_format($invoice->total_cents / 100, 2, '.', ' ');

                if ($result->ok) {
                    $this->settle->settle($invoice, $mandate->gateway->value, $result->gatewayReference);
                    $mandate->forceFill(['failure_count' => 0, 'last_charged_at' => now()])->save();
                    $this->notify($invoice, new SubscriptionDebitNotice('paid', $invoice->number, $amount, $mandate->label()));
                    $paid++;

                    return;
                }

                $mandate->increment('failure_count');
                $final = $attempts->count() + 1 >= self::MAX_ATTEMPTS;
                $this->notify($invoice, new SubscriptionDebitNotice($final ? 'stopped' : 'failed', $invoice->number, $amount, $mandate->label(), $result->error));
                activity('platform')->performedOn($invoice->provider)->withProperties(['invoice' => $invoice->number, 'reason' => $result->error])->log('Automatic payment failed');
                $failed++;
            });

        return ['paid' => $paid, 'failed' => $failed];
    }

    private function notify(SubscriptionInvoice $invoice, SubscriptionDebitNotice $notice): void
    {
        $owners = User::query()->whereIn('id', Membership::query()->where('tenant_id', $invoice->tenant_id)->where('role', 'owner')->pluck('user_id'))->get();
        Notification::send($owners, $notice);
    }
}
