<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Domains\Billing\Actions\CollectSubscriptionDebits;
use App\Domains\Billing\Models\BillingMandate;
use App\Domains\Billing\Models\DebitAttempt;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Platform\Support\PracticeContacts;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: which providers pay automatically, and recent debit attempts.
 * Shows card brand and last four digits only.
 */
class AutoDebitAdminController extends Controller
{
    public function index(): Response
    {
        $mandates = [];
        foreach (BillingMandate::query()->with('provider')->latest('id')->limit(200)->get() as $m) {
            // The latest failure and its open invoice (beta #33).
            $failure = DebitAttempt::query()->where('billing_mandate_id', $m->id)->latest('attempted_at')->first();
            $open = SubscriptionInvoice::query()->where('tenant_id', $m->tenant_id)->where('status', 'open')->oldest('due_at')->first();
            $mandates[] = [
                'id' => $m->id,
                'lastFailure' => $failure !== null && $failure->outcome === 'failed' ? ['reason' => $failure->message, 'at' => $failure->attempted_at->toDateString()] : null,
                'openInvoice' => $open === null ? null : ['number' => $open->number, 'due' => $open->due_at->toDateString(), 'total' => $open->total_cents / 100],
                'revokeNote' => $m->revoke_note,
                'provider' => $m->provider->name,
                'gateway' => $m->gateway->label(),
                'mode' => $m->mode->value,
                'card' => $m->label(),
                'status' => $m->status,
                'failures' => $m->failure_count,
                'lastCharged' => $m->last_charged_at?->toDateString(),
                'since' => $m->consented_at->toDateString(),
            ];
        }

        return Inertia::render('Admin/AutoDebits', [
            'mandates' => $mandates,
            'failedRecently' => DebitAttempt::query()->where('outcome', 'failed')->where('attempted_at', '>', now()->subDays(14))->count(),
        ]);
    }

    /** Retry now (beta #33): one attempt for the open invoice, outside the usual schedule. */
    public function retry(Request $request, BillingMandate $mandate, CollectSubscriptionDebits $debits): RedirectResponse
    {
        if ($mandate->status !== BillingMandate::ACTIVE) {
            return back()->with('error', 'This card is switched off.');
        }
        $invoice = SubscriptionInvoice::query()->with('provider')->where('tenant_id', $mandate->tenant_id)->where('status', 'open')->oldest('due_at')->first();
        if ($invoice === null) {
            return back()->with('error', 'Nothing to collect: this practice has no unpaid invoice.');
        }
        $user = $request->user();
        $outcome = $debits->charge($invoice, true, $user instanceof User ? $user : null);

        return match ($outcome) {
            true => back()->with('success', "Invoice {$invoice->number} paid."),
            false => back()->with('error', "The payment for {$invoice->number} failed again. The practice was emailed."),
            null => back()->with('error', 'Could not try: the payment gateway is not set up for automatic payments.'),
        };
    }

    /** Stop auto-debit (beta #33): the saved card is switched off; the practice can save a new one. */
    public function stop(Request $request, BillingMandate $mandate): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200']], ['note.required' => 'Give the reason for stopping.']);
        $mandate->forceFill(['status' => BillingMandate::REVOKED, 'revoked_at' => now(), 'revoke_note' => 'Stopped by Dr Business Flow: '.$data['note']])->save();
        activity('platform')->causedBy($request->user())->performedOn($mandate->provider)->withProperties(['mandate' => $mandate->id, 'note' => $data['note']])->log('Automatic payment stopped by admin');
        Notification::send(PracticeContacts::owners($mandate->tenant_id), new PracticeNotice('Automatic payment switched off',
            ["Dr Business Flow switched off automatic payment with your {$mandate->label()}.", 'Reason: '.$data['note'], 'You can save a card again on the Subscription page.'],
            'Subscription', PracticeContacts::url($mandate->provider, '/settings/subscription'), 'debit_stopped_admin'));

        return back()->with('success', 'Automatic payment stopped. The practice was emailed.');
    }
}
