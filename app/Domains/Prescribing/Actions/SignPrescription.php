<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Actions;

use App\Domains\Documents\Actions\RenderDocument;
use App\Domains\Documents\Support\DocumentType;
use App\Domains\Documents\Support\PracticeData;
use App\Domains\Hub\Actions\EscriptExchange;
use App\Domains\Identity\Models\Staff;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\PrescriptionItem;
use App\Domains\Prescribing\Models\SigningChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Signs a draft script with the PIN: re-checks safety, freezes the version,
 * stores a signature hash over its content, supersedes the previous version
 * and issues the branded PDF.
 */
class SignPrescription
{
    public function __construct(private readonly CheckPrescriptionSafety $safety, private readonly RenderDocument $render) {}

    public function handle(Prescription $prescription, Staff $doctor, string $pin): Prescription
    {
        if ($prescription->status !== Prescription::DRAFT || $prescription->prescriber_staff_id !== $doctor->id) {
            throw ValidationException::withMessages(['pin' => 'This script cannot be signed by you.']);
        }

        $challenge = SigningChallenge::query()->where('prescription_id', $prescription->id)->where('staff_id', $doctor->id)
            ->whereNull('consumed_at')->latest('id')->first();
        if (! $challenge instanceof SigningChallenge || $challenge->expires_at->isPast() || $challenge->attempts >= SigningChallenge::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['pin' => 'The signing PIN has expired. Request a new one.']);
        }
        if (! Hash::check($pin, $challenge->code_hash)) {
            $challenge->increment('attempts');
            throw ValidationException::withMessages(['pin' => 'That PIN is not correct.']);
        }

        if (! $this->safety->canSign($prescription, $this->safety->handle($prescription))) {
            throw ValidationException::withMessages(['pin' => 'Resolve the safety warnings before signing.']);
        }

        $signed = DB::transaction(function () use ($prescription, $doctor, $challenge): Prescription {
            $challenge->forceFill(['consumed_at' => now()])->save();
            $signedAt = now();
            $items = $prescription->items()->orderBy('id')->get()
                ->map(fn (PrescriptionItem $i) => [$i->nappi_code, $i->dose, $i->quantity, $i->repeats, $i->override_reason])->all();
            $hash = hash('sha256', json_encode([$prescription->id, $prescription->version, $prescription->patient_id, $doctor->id, $signedAt->toIso8601String(), $items], JSON_THROW_ON_ERROR));

            Prescription::query()->where('consultation_id', $prescription->consultation_id)->where('status', Prescription::SIGNED)
                ->where('version', '<', $prescription->version)->get()->each(fn (Prescription $old) => $old->forceFill(['status' => Prescription::SUPERSEDED])->save());

            $prescription->forceFill(['status' => Prescription::SIGNED, 'signed_at' => $signedAt, 'signature_hash' => $hash])->save();

            activity('clinical')->performedOn($prescription)->causedBy(User::query()->find($doctor->id))
                ->withProperties(['version' => $prescription->version, 'hash' => $hash])->log('Prescription signed');

            return $prescription;
        });

        $patient = $signed->patient;
        $document = $this->render->issue(DocumentType::Prescription, $signed, [
            'practice' => PracticeData::get(),
            'patient' => ['name' => $patient->fullName(), 'age' => $patient->ageInYears()],
            'doctor' => ['name' => $doctor->name, 'hpcsa' => (string) $doctor->professional_number],
            'document' => ['date' => now()->format('j M Y'), 'signed_at' => $signed->signed_at?->format('j M Y H:i')],
            'lines' => $signed->items()->get()->map(fn (PrescriptionItem $i) => [
                'description' => $i->description, 'dose' => $i->dose, 'quantity' => (string) $i->quantity, 'repeats' => (string) $i->repeats,
            ])->all(),
        ], User::query()->find($doctor->id));

        $signed->forceFill(['issued_document_id' => $document->id])->save();

        $issuer = tenant();
        if ($issuer !== null && $signed->version > 1) {
            app(EscriptExchange::class)->supersede((string) $issuer->getTenantKey(), $signed->consultation_id, $signed->version);
        }

        return $signed;
    }
}
