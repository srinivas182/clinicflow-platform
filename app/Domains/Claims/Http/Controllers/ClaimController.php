<?php

declare(strict_types=1);

namespace App\Domains\Claims\Http\Controllers;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Claims\Actions\CheckEligibility;
use App\Domains\Claims\Actions\ImportRemittances;
use App\Domains\Claims\Actions\SubmitClaim;
use App\Domains\Claims\Models\Claim;
use App\Domains\Claims\Support\ClaimAgeing;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Patients\Models\Patient;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Eligibility checks at the front desk and the claims worklist for billing.
 */
class ClaimController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize(Permission::CLAIMS_MANAGE);
        $status = $request->string('status')->toString();

        $claims = Claim::query()->with('patient')->when($status !== '', fn ($q) => $q->where('status', $status))->latest()->paginate(50)->withQueryString();
        $unclaimed = Invoice::query()->with('patient')->where('payer_type', PayerType::MedicalAid->value)
            ->whereNotIn('id', Claim::query()->select('invoice_id'))->latest()->limit(100)->get();

        return Inertia::render('Claims/Index', [
            'status' => $status,
            'claims' => $claims->through(fn (Claim $c) => [
                'id' => $c->id, 'invoiceId' => $c->invoice_id, 'patient' => $c->patient->fullName(), 'scheme' => $c->scheme,
                'member' => $c->member_number, 'total' => $c->total_cents / 100, 'status' => $c->status,
                'reason' => $c->rejection_reason, 'reference' => $c->switch_reference, 'submissions' => $c->submissions,
            ]),
            'ageing' => array_map(fn (int $c) => $c / 100, ClaimAgeing::buckets()),
            'unclaimed' => $unclaimed->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'patient' => $i->patient->fullName(), 'total' => $i->total_cents / 100,
            ])->values(),
        ]);
    }

    public function submit(Request $request, Invoice $invoice, SubmitClaim $action): RedirectResponse
    {
        $this->authorize(Permission::CLAIMS_MANAGE);
        $claim = $action->handle($invoice, $this->user($request));

        return back()->with('success', $claim->status === 'accepted' ? "Claim accepted ({$claim->switch_reference})." : "Claim rejected: {$claim->rejection_reason}");
    }

    public function importRemittances(ImportRemittances $action): RedirectResponse
    {
        $this->authorize(Permission::CLAIMS_MANAGE);
        $result = $action->handle();

        return back()->with('success', "{$result['applied']} remittance(s) applied, {$result['shortfalls']} with a patient co-payment.");
    }

    public function eligibility(Request $request, Patient $patient, CheckEligibility $action): RedirectResponse
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $visitId = $request->string('visit_id')->toString();
        $check = $action->handle($patient, $visitId !== '' ? Visit::query()->find($visitId) : null, $this->user($request));

        return back()->with('success', $check->message);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
