<?php

declare(strict_types=1);

namespace App\Domains\Claims\Actions;

use App\Domains\Billing\Enums\LineKind;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\InvoiceLine;
use App\Domains\Claims\Contracts\ClaimsSwitch;
use App\Domains\Claims\Models\Claim;
use App\Domains\Claims\Models\ClaimLine;
use App\Domains\Claims\Support\ClaimSubmission;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Clinical\Models\ConsultationDiagnosis;
use App\Domains\Platform\Models\Setting;
use App\Domains\Visits\Enums\PayerType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds the claim from the visit's invoice (tariff and NAPPI codes) and the
 * consultation's ICD-10 diagnoses (primary first), then submits it. Rejected
 * claims are rebuilt and resubmitted after correction.
 */
class SubmitClaim
{
    public function __construct(private readonly ClaimsSwitch $switch) {}

    public function handle(Invoice $invoice, ?User $by = null): Claim
    {
        $invoice->loadMissing(['lines', 'patient', 'visit']);
        $patient = $invoice->patient;

        if ($invoice->payer_type !== PayerType::MedicalAid) {
            throw ValidationException::withMessages(['invoice' => 'Only medical aid invoices are claimed.']);
        }
        if (blank($patient->medical_aid_scheme) || blank($patient->medical_aid_number)) {
            throw ValidationException::withMessages(['medical_aid' => 'The patient has no scheme or member number.']);
        }

        $codes = $invoice->visit_id === null ? [] : ConsultationDiagnosis::query()
            ->whereIn('consultation_id', Consultation::query()->where('visit_id', $invoice->visit_id)->pluck('id'))
            ->orderByDesc('is_primary')->pluck('icd10_code')->all();
        if ($codes === []) {
            throw ValidationException::withMessages(['diagnoses' => 'The consultation has no ICD-10 diagnosis yet.']);
        }

        $existing = Claim::query()->where('invoice_id', $invoice->id)->first();
        if ($existing !== null && in_array($existing->status, ['submitted', 'accepted', 'paid'], true)) {
            throw ValidationException::withMessages(['claim' => 'This claim has already been accepted by the switch.']);
        }

        return DB::transaction(function () use ($invoice, $patient, $codes, $existing, $by): Claim {
            $claim = $existing ?? Claim::create([
                'invoice_id' => $invoice->id,
                'patient_id' => $patient->id,
                'scheme' => (string) $patient->medical_aid_scheme,
                'member_number' => (string) $patient->medical_aid_number,
                'dependant_code' => $patient->medical_aid_dependant_code,
                'status' => 'draft',
                'total_cents' => $invoice->total_cents,
            ]);

            $claim->forceFill([
                'scheme' => (string) $patient->medical_aid_scheme,
                'member_number' => (string) $patient->medical_aid_number,
                'dependant_code' => $patient->medical_aid_dependant_code,
                'total_cents' => $invoice->total_cents,
            ])->save();

            $claim->lines()->delete();
            foreach ($invoice->lines as $line) {
                /** @var InvoiceLine $line */
                $claim->lines()->create([
                    'tariff_code' => $line->kind === LineKind::Medicine ? null : $line->code,
                    'nappi_code' => $line->kind === LineKind::Medicine ? $line->code : null,
                    'icd10_codes' => $codes,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'amount_cents' => $line->total_cents,
                ]);
            }

            $result = $this->switch->submit(new ClaimSubmission(
                claimId: $claim->id,
                practiceNumber: (string) Setting::get('branding', 'bhf', ''),
                scheme: $claim->scheme,
                memberNumber: $claim->member_number,
                dependantCode: $claim->dependant_code,
                patientName: $patient->fullName(),
                dateOfService: ($invoice->created_at ?? now())->toDateString(),
                totalCents: $claim->total_cents,
                lines: $claim->lines()->get()->map(fn (ClaimLine $l) => [
                    'tariff_code' => $l->tariff_code, 'nappi_code' => $l->nappi_code, 'icd10_codes' => $l->icd10_codes,
                    'description' => $l->description, 'quantity' => $l->quantity, 'amount_cents' => $l->amount_cents,
                ])->values()->all(),
            ));

            $claim->forceFill([
                'status' => $result->accepted ? 'accepted' : 'rejected',
                'submissions' => $claim->submissions + 1,
                'switch' => $this->switch->name(),
                'switch_reference' => $result->reference,
                'rejection_reason' => $result->reason,
                'response' => $result->raw,
                'submitted_at' => now(),
            ])->save();

            activity('claims')->performedOn($claim)->causedBy($by)->withProperties(['status' => $claim->status, 'reason' => $result->reason])->log('Claim submitted');

            return $claim;
        });
    }
}
