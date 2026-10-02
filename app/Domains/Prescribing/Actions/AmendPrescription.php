<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Actions;

use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\PrescriptionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A change after signing (e.g. a pharmacist query) never edits the signed
 * script: it opens the next version as a draft copy.
 */
class AmendPrescription
{
    public function handle(Prescription $signed, string $reason): Prescription
    {
        if ($signed->status !== Prescription::SIGNED || ! $signed->isDispensable()) {
            throw ValidationException::withMessages(['prescription' => 'Only the current signed version can be amended.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the change.']);
        }
        if (Prescription::query()->where('consultation_id', $signed->consultation_id)->where('status', Prescription::DRAFT)->exists()) {
            throw ValidationException::withMessages(['prescription' => 'A new version is already being prepared.']);
        }

        return DB::transaction(function () use ($signed, $reason): Prescription {
            $next = Prescription::create([
                'consultation_id' => $signed->consultation_id,
                'patient_id' => $signed->patient_id,
                'prescriber_staff_id' => $signed->prescriber_staff_id,
                'version' => $signed->version + 1,
                'previous_version_id' => $signed->id,
                'status' => Prescription::DRAFT,
                'change_reason' => trim($reason),
            ]);

            $signed->items()->get()->each(fn (PrescriptionItem $i) => $next->items()->create(
                $i->only(['medicine_id', 'nappi_code', 'description', 'schedule', 'dose', 'quantity', 'repeats', 'override_reason'])
            ));

            activity('clinical')->performedOn($next)->withProperties(['from_version' => $signed->version, 'reason' => $reason])->log('Prescription amendment started');

            return $next;
        });
    }
}
