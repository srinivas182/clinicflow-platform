<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Actions\AddInvoiceLine;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\RefundPayment;
use App\Domains\Billing\Actions\RemoveInvoiceLine;
use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\InvoiceLine;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Support\BillingSettings;
use App\Domains\Identity\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function show(Request $request, Invoice $invoice): Response
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $invoice->load(['lines', 'payments', 'patient']);

        return Inertia::render('Billing/Invoice', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'patient' => $invoice->patient->fullName(),
                'payer' => $invoice->payer_type->value,
                'status' => $invoice->status->value,
                'total' => $invoice->total_cents / 100,
                'paid' => $invoice->paid_cents / 100,
                'balance' => $invoice->balanceCents() / 100,
                'needsReview' => $invoice->needs_review,
                'reviewNote' => $invoice->review_note,
                'lines' => $invoice->lines->map(fn (InvoiceLine $l) => [
                    'id' => $l->id, 'code' => $l->code, 'description' => $l->description, 'quantity' => $l->quantity,
                    'total' => $l->total_cents / 100, 'locked' => $l->locked_at !== null,
                ])->values(),
                'payments' => $invoice->payments->map(fn (Payment $p) => [
                    'id' => $p->id, 'method' => $p->method->label(), 'amount' => $p->amount_cents / 100, 'refunded' => $p->refunded_cents / 100,
                    'status' => $p->status->value, 'reference' => $p->reference, 'refundable' => $p->refundableCents() / 100,
                ])->values(),
            ],
            'refundRule' => BillingSettings::refundRule()->value,
            'canRefund' => $this->user($request)->can(Permission::BILLING_REFUND),
        ]);
    }

    public function addLine(Request $request, Invoice $invoice, AddInvoiceLine $action): RedirectResponse
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $data = $request->validate([
            'kind' => ['required', Rule::enum(LineKind::class)],
            'description' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:24'],
            'amount' => ['required', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        $action->handle($invoice, LineKind::from($data['kind']), $data['description'], (int) round(((float) $data['amount']) * 100), (int) $data['quantity'], $data['code'] ?? null);

        return back()->with('success', 'Line added.');
    }

    public function removeLine(InvoiceLine $line, RemoveInvoiceLine $action): RedirectResponse
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $action->handle($line);

        return back()->with('success', 'Line removed.');
    }

    public function pay(Request $request, Invoice $invoice, RecordPayment $action): RedirectResponse
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $data = $request->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:64'],
        ]);
        $payment = $action->handle($invoice, PaymentMethod::from($data['method']), (int) round(((float) $data['amount']) * 100), $data['reference'] ?? null, $this->user($request));

        return back()->with('success', $payment->method === PaymentMethod::PayLink ? 'Pay link sent to the patient.' : 'Payment recorded.');
    }

    public function refund(Request $request, Payment $payment, RefundPayment $action): RedirectResponse
    {
        $this->authorize(Permission::BILLING_REFUND);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:64'],
        ]);
        $action->handle($payment, (int) round(((float) $data['amount']) * 100), $data['reason'], $this->user($request), $data['reference'] ?? null);

        return back()->with('success', 'Refund recorded.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
