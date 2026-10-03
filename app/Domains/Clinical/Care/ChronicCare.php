<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Care;

use App\Domains\Clinical\Models\Consultation;
use App\Domains\Clinical\Models\Icd10Code;
use App\Domains\Clinical\Models\Problem;
use App\Domains\Clinical\Models\TriageRecord;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Lab\Models\LabResult;
use App\Domains\Patients\Models\Patient;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\PrescriptionItem;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Problem list, repeat prescriptions for chronic medicine and monitoring due dates.
 * Monitoring rules are DEMO defaults for the clinical reviewer to confirm.
 */
class ChronicCare
{
    /** ICD-10 prefix => list of [label, lab test code or "BP"/"EYE", months]. */
    public const MONITORING = [
        'E11' => [['HbA1c', 'HBA1C', 6], ['Kidney function', 'CREAT', 12], ['Eye examination', 'EYE', 12]],
        'I10' => [['Blood pressure', 'BP', 3], ['Kidney function', 'CREAT', 12]],
        'E78' => [['Cholesterol', 'CHOL', 12]],
        'N18' => [['Kidney function', 'CREAT', 6], ['Potassium', 'K', 6]],
        'J45' => [['Asthma review', 'BP', 6]],
    ];

    public function addProblem(Patient $patient, string $code, ?string $onset, Staff $by, bool $chronic = true): Problem
    {
        $icd = Icd10Code::query()->find(strtoupper($code));
        if (! $icd instanceof Icd10Code) {
            throw ValidationException::withMessages(['icd10_code' => 'Choose a valid ICD-10 code.']);
        }

        return Problem::query()->firstOrCreate(['patient_id' => $patient->id, 'icd10_code' => $icd->code, 'status' => 'active'],
            ['description' => $icd->description, 'chronic' => $chronic, 'onset_date' => $onset, 'added_by' => $by->id]);
    }

    public function resolveProblem(Problem $problem): void
    {
        $problem->forceFill(['status' => 'resolved'])->save();
    }

    /**
     * A repeat of a signed chronic script: a new draft (on a "repeat" virtual visit) that
     * the doctor checks and signs with their PIN; safety and repeat limits apply again.
     */
    public function renew(Prescription $previous, Staff $doctor): Prescription
    {
        if ($previous->status !== Prescription::SIGNED || ! (bool) $previous->getAttribute('chronic')) {
            throw ValidationException::withMessages(['prescription' => 'Only a signed chronic prescription can be renewed.']);
        }

        return DB::transaction(function () use ($previous, $doctor): Prescription {
            $seq = Visit::query()->whereDate('visit_date', today())->where('ticket', 'like', 'R%')->lockForUpdate()->count() + 1;
            $visit = Visit::create([
                'patient_id' => $previous->patient_id, 'visit_date' => today(), 'ticket' => 'R'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
                'stage' => VisitStage::Done, 'payer_type' => PayerType::Cash, 'doctor_id' => $doctor->id, 'check_in_channel' => 'repeat', 'stage_changed_at' => now(),
            ]);
            $consult = Consultation::create(['visit_id' => $visit->id, 'patient_id' => $previous->patient_id, 'doctor_staff_id' => $doctor->id, 'plan' => 'Chronic repeat prescription']);
            $draft = Prescription::create([
                'consultation_id' => $consult->id, 'patient_id' => $previous->patient_id, 'prescriber_staff_id' => $doctor->id,
                'version' => 1, 'status' => Prescription::DRAFT, 'chronic' => true, 'change_reason' => 'Chronic repeat of '.$previous->id,
            ]);
            $previous->items()->get()->each(fn (PrescriptionItem $i) => $draft->items()->create($i->only(['medicine_id', 'nappi_code', 'description', 'schedule', 'dose', 'quantity', 'repeats', 'override_reason'])));

            return $draft;
        });
    }

    /**
     * @return list<array{problem: string, check: string, last: ?string, due: string, overdue: bool}>
     */
    public function monitoringDue(Patient $patient): array
    {
        $due = [];
        foreach (Problem::query()->where('patient_id', $patient->id)->where('status', 'active')->where('chronic', true)->get() as $problem) {
            foreach (self::MONITORING as $prefix => $checks) {
                if (! str_starts_with($problem->icd10_code, $prefix)) {
                    continue;
                }
                foreach ($checks as [$label, $code, $months]) {
                    $last = $this->lastDone($patient->id, $code);
                    $dueOn = $last === null ? CarbonImmutable::today() : $last->addMonths($months);
                    $due[] = ['problem' => $problem->description, 'check' => $label, 'last' => $last?->toDateString(), 'due' => $dueOn->toDateString(), 'overdue' => $dueOn->lte(today())];
                }
            }
        }

        return $due;
    }

    private function lastDone(string $patientId, string $code): ?CarbonImmutable
    {
        if ($code === 'BP') {
            $at = TriageRecord::query()->whereIn('visit_id', Visit::query()->where('patient_id', $patientId)->select('id'))->max('created_at');
        } elseif ($code === 'EYE') {
            $at = null;
        } else {
            $at = LabOrder::query()->where('patient_id', $patientId)->whereIn('status', ['verified', 'released', 'discuss'])
                ->whereIn('id', LabResult::query()->where('test_code', $code)->select('lab_order_id'))->max('verified_at');
        }

        return $at === null ? null : CarbonImmutable::parse((string) $at)->startOfDay();
    }
}
