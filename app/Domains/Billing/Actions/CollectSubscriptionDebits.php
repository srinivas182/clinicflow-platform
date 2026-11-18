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
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Platform\Support\PracticeContacts;
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
                $outcome = $this->charge($invoice);
                $paid += $outcome === true ? 1 : 0;
                $failed += $outcome === false ? 1 : 0;
            });

        return ['paid' => $paid, 'failed' => $failed];
    }

    /**
     * One attempt for one invoice: true paid, false failed, null not attempted (no card, waiting
     * between tries, or no gateway). $manual (admin "Retry now") skips the wait and the 3-try limit.
     * After the last automatic failure the card is switched off, the admin alerted and the owner
     * emailed a Pay now link (beta #34).
     */
    public function charge(SubscriptionInvoice $invoice, bool $manual = false, ?User $by = null): ?bool
    {
        $mandate = BillingMandate::activeFor($invoice->tenant_id);
        if (! $mandate instanceof BillingMandate || $mandate->gateway->chargesMandateItself() || ! $mandate->gateway->supportsAutoDebit()) {
            return null;
        }

        $attempts = DebitAttempt::query()->where('subscription_invoice_id', $invoice->id)->orderByDesc('attempted_at')->get();
        $last = $attempts->first();
        if (! $manual && ($attempts->count() >= self::MAX_ATTEMPTS || ($last !== null && $last->attempted_at->gt(now()->subDays(self::RETRY_AFTER_DAYS))))) {
            return null;
        }

        $config = PlatformGatewayConfig::query()->where('gateway', $mandate->gateway->value)->where('enabled', true)->first();
        $gateway = $config instanceof PlatformGatewayConfig ? GatewayFactory::fromConfig($config) : null;
        if (! $gateway instanceof RecurringPaymentGateway) {
            return null;
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
            activity('platform')->causedBy($by)->performedOn($invoice->provider)->withProperties(['invoice' => $invoice->number, 'manual' => $manual])->log('Automatic payment collected');

            return true;
        }

        $mandate->increment('failure_count');
        $final = ! $manual && $attempts->count() + 1 >= self::MAX_ATTEMPTS;
        activity('platform')->causedBy($by)->performedOn($invoice->provider)->withProperties(['invoice' => $invoice->number, 'reason' => $result->error, 'manual' => $manual])->log('Automatic payment failed');
        if ($final) {
            // Three failures: stop using this card so it is not charged again by mistake.
            $mandate->forceFill(['status' => BillingMandate::REVOKED, 'revoked_at' => now(), 'revoke_note' => 'Stopped after '.self::MAX_ATTEMPTS.' failed payments'])->save();
            $practice = $invoice->provider->name;
            Notification::send(PracticeContacts::platformAdmins(), new PracticeNotice("Automatic payment stopped: {$practice}",
                ["Three payments failed for invoice {$invoice->number} ({$amount}); the saved card was switched off.", 'Last reason: '.($result->error ?? 'not given')],
                'Open auto-debits', rtrim((string) config('app.url'), '/').'/admin/auto-debits', 'debit_stopped'));
        }
        $this->notify($invoice, new SubscriptionDebitNotice($final ? 'stopped' : 'failed', $invoice->number, $amount, $mandate->label(), $result->error,
            $final ? rtrim((string) config('app.url'), '/')."/billing/pay/{$invoice->checkout_token}" : null));

        return false;
    }

    private function notify(SubscriptionInvoice $invoice, SubscriptionDebitNotice $notice): void
    {
        $owners = User::query()->whereIn('id', Membership::query()->where('tenant_id', $invoice->tenant_id)->where('role', 'owner')->pluck('user_id'))->get();
        Notification::send($owners, $notice);
    }
}
