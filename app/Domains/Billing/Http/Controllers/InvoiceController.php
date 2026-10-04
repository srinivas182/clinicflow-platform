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
use App\Domains\Billing\Prepaid\PrepaidPackages;
use App\Domains\Billing\Support\BillingSettings;
use App\Domains\Billing\Support\Vat;
use App\Domains\Documents\Actions\RenderDocument;
use App\Domains\Documents\Support\DocumentType;
use App\Domains\Documents\Support\PracticeData;
use App\Domains\Identity\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                    'service' => PrepaidPackages::serviceFor($l),
                ])->values(),
                'payments' => $invoice->payments->map(fn (Payment $p) => [
                    'id' => $p->id, 'method' => $p->method->label(), 'amount' => $p->amount_cents / 100, 'refunded' => $p->refunded_cents / 100,
                    'status' => $p->status->value, 'reference' => $p->reference, 'refundable' => $p->refundableCents() / 100,
                    'gateway' => $p->gateway, 'payLink' => $p->checkout_token !== null && $p->status->value === 'pending' ? '/pay/'.$p->checkout_token : null,
                ])->values(),
            ],
            'packages' => DB::table('patient_packages')->join('prepaid_packages', 'prepaid_packages.id', '=', 'patient_packages.prepaid_package_id')
                ->where('patient_packages.patient_id', $invoice->patient_id)->where('patient_packages.status', 'active')->where('patient_packages.expires_at', '>', now())
                ->get(['patient_packages.id', 'patient_packages.remaining', 'prepaid_packages.name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'remaining' => json_decode((string) $p->remaining, true)])->values(),
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

        return back()->with('success', $payment->method === PaymentMethod::PayLink
            ? 'Pay link ready — send it to the patient: '.$request->getSchemeAndHttpHost().'/pay/'.$payment->checkout_token
            : 'Payment recorded.');
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

    public function pdf(Request $request, Invoice $invoice, RenderDocument $render): StreamedResponse
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $invoice->load(['lines', 'patient']);
        $money = fn (int $cents): string => 'R'.number_format($cents / 100, 2, '.', ' ');

        $issued = $render->issue(DocumentType::Invoice, $invoice, [
            'practice' => PracticeData::get(),
            'patient' => ['name' => $invoice->patient->fullName(), 'medical_aid' => $invoice->patient->medical_aid_scheme ?? 'Cash'],
            'invoice' => ['number' => $invoice->number, 'date' => $invoice->created_at?->format('j F Y'), 'total' => $money($invoice->total_cents), 'paid' => $money($invoice->paid_cents), 'balance' => $money($invoice->balanceCents()),
                'title' => $invoice->tax_invoice ? 'Tax invoice' : 'Invoice', 'vat' => $money($invoice->vat_cents), 'vat_number' => (string) Vat::number(), 'vat_rate' => (string) $invoice->vat_rate],
            'lines' => $invoice->lines->map(fn (InvoiceLine $l) => ['code' => $l->code ?? '', 'description' => $l->description, 'quantity' => (string) $l->quantity, 'total' => $money($l->total_cents)])->all(),
        ], $this->user($request));

        return Storage::disk('local')->download($issued->file_path, "{$invoice->number}.pdf", ['Content-Type' => 'application/pdf']);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
