<?php

declare(strict_types=1);

namespace App\Domains\Hub\Actions;

use App\Domains\Hub\Models\HubEscript;
use App\Domains\Hub\Models\HubLink;
use App\Domains\Identity\Models\Staff;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\PrescriptionItem;
use Illuminate\Validation\ValidationException;

/**
 * Sends the current signed script to the network pharmacy the patient chose.
 * The pharmacy sees exactly the signed content and its signature fingerprint;
 * a newer version cancels earlier e-scripts, and a script is dispensed once.
 */
class EscriptExchange
{
    public function send(Prescription $prescription, Provider $issuer, string $pharmacyId): HubEscript
    {
        if (! $prescription->isDispensable() || $prescription->signature_hash === null) {
            throw ValidationException::withMessages(['prescription' => 'Only the current signed version can be sent.']);
        }

        $pharmacy = Provider::query()->find($pharmacyId);
        if (! $pharmacy instanceof Provider || $pharmacy->type !== ProviderType::Pharmacy || ! in_array($pharmacy->status, [ProviderStatus::Trial, ProviderStatus::Active], true)) {
            throw ValidationException::withMessages(['pharmacy_id' => 'Choose a pharmacy that is live on the Clinic Flow network.']);
        }

        $patient = $prescription->patient;
        $identityId = $patient->getAttribute('hub_identity_id');
        $linked = is_string($identityId) && HubLink::query()->where('identity_id', $identityId)->where('tenant_id', $issuer->id)->where('status', 'active')->exists();
        if (! $linked) {
            throw ValidationException::withMessages(['prescription' => 'This patient is not linked to the network yet. Link them (Network search) before sending e-scripts.']);
        }

        $open = HubEscript::query()->where('issuer_tenant_id', $issuer->id)->where('prescription_id', $prescription->id)->whereIn('status', ['sent', 'accepted', 'dispensed'])->exists();
        if ($open) {
            throw ValidationException::withMessages(['prescription' => 'This script has already been sent to a pharmacy.']);
        }

        $doctor = Staff::query()->find($prescription->prescriber_staff_id);
        $escript = HubEscript::create([
            'identity_id' => $identityId,
            'issuer_tenant_id' => $issuer->id,
            'prescription_id' => $prescription->id,
            'consultation_id' => $prescription->consultation_id,
            'version' => $prescription->version,
            'pharmacy_tenant_id' => $pharmacy->id,
            'payload' => [
                'patient' => ['name' => $patient->fullName(), 'date_of_birth' => $patient->date_of_birth->toDateString()],
                'doctor' => ['name' => $doctor?->name, 'hpcsa' => $doctor?->professional_number],
                'practice' => $issuer->name,
                'signed_at' => $prescription->signed_at?->toIso8601String(),
                'items' => $prescription->items()->get()->map(fn (PrescriptionItem $i) => $i->only(['description', 'nappi_code', 'schedule', 'dose', 'quantity', 'repeats']))->values()->all(),
            ],
            'signature_hash' => $prescription->signature_hash,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        activity('hub')->performedOn($prescription)->withProperties(['pharmacy' => $pharmacy->name, 'escript' => $escript->id])->log('E-script sent to pharmacy');

        return $escript;
    }

    /**
     * A newly signed version cancels e-scripts of earlier versions that were not dispensed.
     */
    public function supersede(string $issuerId, string $consultationId, int $newVersion): int
    {
        return HubEscript::query()->where('issuer_tenant_id', $issuerId)->where('consultation_id', $consultationId)
            ->where('version', '<', $newVersion)->whereIn('status', ['sent', 'accepted'])
            ->update(['status' => 'cancelled', 'status_note' => "Replaced by version {$newVersion}", 'updated_at' => now()]);
    }

    public function accept(HubEscript $escript, Provider $pharmacy): HubEscript
    {
        $this->own($escript, $pharmacy, 'sent');
        $escript->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();

        return $escript;
    }

    public function reject(HubEscript $escript, Provider $pharmacy, string $reason): HubEscript
    {
        $this->own($escript, $pharmacy, 'sent');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give the reason for the doctor and patient.']);
        }
        $escript->forceFill(['status' => 'rejected', 'status_note' => trim($reason)])->save();

        return $escript;
    }

    public function dispense(HubEscript $escript, Provider $pharmacy): HubEscript
    {
        $this->own($escript, $pharmacy, 'accepted');
        $escript->forceFill(['status' => 'dispensed', 'dispensed_at' => now()])->save();

        return $escript;
    }

    private function own(HubEscript $escript, Provider $pharmacy, string $expected): void
    {
        abort_unless($escript->pharmacy_tenant_id === $pharmacy->id, 403);
        if ($escript->status !== $expected) {
            throw ValidationException::withMessages(['escript' => $escript->status === 'cancelled'
                ? 'This script was replaced by a newer version and cannot be dispensed.'
                : "This e-script is {$escript->status}."]);
        }
    }
}
