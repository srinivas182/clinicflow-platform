<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Actions;

use App\Domains\Clinical\Models\Consultation;
use App\Domains\Clinical\Models\Icd10Code;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves SOAP notes and ICD-10 diagnoses. Optimistic locking: if someone else
 * saved since this screen loaded, the save is refused instead of overwriting.
 */
class SaveConsultation
{
    /**
     * @param  array{subjective?: ?string, objective?: ?string, assessment?: ?string, plan?: ?string}  $notes
     * @param  list<array{code: string, primary: bool}>  $diagnoses
     */
    public function handle(Consultation $consultation, array $notes, array $diagnoses, int $lockVersion): Consultation
    {
        if ($consultation->isCompleted()) {
            throw ValidationException::withMessages(['consultation' => 'This consultation is completed and can no longer be edited.']);
        }

        $codes = array_values(array_unique(array_map(fn (array $d) => strtoupper(trim($d['code'])), $diagnoses)));
        $known = Icd10Code::query()->whereIn('code', $codes)->get()->keyBy('code');
        $unknown = array_diff($codes, $known->keys()->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['diagnoses' => 'Unknown ICD-10 code: '.implode(', ', $unknown).'.']);
        }

        $primary = array_values(array_filter($diagnoses, fn (array $d) => $d['primary']));
        if (count($primary) > 1) {
            throw ValidationException::withMessages(['diagnoses' => 'Choose one primary diagnosis.']);
        }
        if ($primary !== [] && ! $known[strtoupper($primary[0]['code'])]->valid_primary) {
            throw ValidationException::withMessages(['diagnoses' => strtoupper($primary[0]['code']).' cannot be a primary diagnosis.']);
        }

        return DB::transaction(function () use ($consultation, $notes, $diagnoses, $lockVersion, $known): Consultation {
            $updated = Consultation::query()->whereKey($consultation->id)->where('lock_version', $lockVersion)->update([
                'subjective' => $notes['subjective'] ?? null,
                'objective' => $notes['objective'] ?? null,
                'assessment' => $notes['assessment'] ?? null,
                'plan' => $notes['plan'] ?? null,
                'lock_version' => $lockVersion + 1,
                'updated_at' => now(),
            ]);

            if ($updated === 0) {
                throw ValidationException::withMessages(['consultation' => 'Someone else saved this consultation after you opened it. Reload to see their changes.']);
            }

            $consultation->diagnoses()->delete();
            foreach ($diagnoses as $d) {
                $code = strtoupper(trim($d['code']));
                $consultation->diagnoses()->create(['icd10_code' => $code, 'description' => $known[$code]->description, 'is_primary' => $d['primary']]);
            }

            return $consultation->refresh();
        });
    }
}
