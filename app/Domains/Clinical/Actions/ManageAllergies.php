<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Actions;

use App\Domains\Clinical\Models\Allergy;
use App\Domains\Patients\Models\Patient;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Allergies are added freely; removing one needs a reason and is audited.
 */
class ManageAllergies
{
    public function add(Patient $patient, string $substance, ?string $reaction = null, ?User $by = null): Allergy
    {
        $substance = trim($substance);
        if ($substance === '') {
            throw ValidationException::withMessages(['substance' => 'Name the substance.']);
        }

        $existing = Allergy::query()->where('patient_id', $patient->id)->where('status', 'active')->whereRaw('LOWER(substance) = ?', [mb_strtolower($substance)])->first();
        if ($existing instanceof Allergy) {
            return $existing;
        }

        $allergy = Allergy::create(['patient_id' => $patient->id, 'substance' => $substance, 'reaction' => $reaction, 'recorded_by' => $by?->id]);
        activity('clinical')->performedOn($patient)->causedBy($by)->withProperties(['substance' => $substance])->log('Allergy added');

        return $allergy;
    }

    public function remove(Allergy $allergy, string $reason, ?User $by = null): Allergy
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for removing an allergy.']);
        }

        $allergy->forceFill(['status' => 'removed', 'removed_reason' => trim($reason), 'removed_by' => $by?->id])->save();
        activity('clinical')->causedBy($by)->withProperties(['allergy_id' => $allergy->id, 'substance' => $allergy->substance, 'reason' => $reason])->log('Allergy removed');

        return $allergy;
    }
}
