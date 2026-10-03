<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Support;

use App\Domains\Clinical\Models\Allergy;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Clinical\Models\Problem;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Lab\Models\LabResult;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\PrescriptionItem;

/**
 * The clinical summary for a referral, built only from the categories the patient agreed to share.
 */
final class ClinicalSummary
{
    /**
     * @param  list<string>  $categories
     * @return array<string, mixed>
     */
    public static function build(string $patientId, array $categories): array
    {
        $summary = [];
        if (in_array('allergies', $categories, true)) {
            $summary['allergies'] = Allergy::query()->where('patient_id', $patientId)->where('status', 'active')->pluck('substance')->all();
        }
        if (in_array('medicines', $categories, true)) {
            $summary['medicines'] = PrescriptionItem::query()->whereIn('prescription_id', Prescription::query()->where('patient_id', $patientId)
                ->where('status', Prescription::SIGNED)->where('signed_at', '>', now()->subDays(180))->select('id'))->get()
                ->map(fn (PrescriptionItem $i) => "{$i->description} — {$i->dose}")->unique()->values()->all();
        }
        if (in_array('problems', $categories, true)) {
            $summary['problems'] = Problem::query()->where('patient_id', $patientId)->where('status', 'active')->get()->map(fn (Problem $p) => "{$p->icd10_code} {$p->description}")->all();
        }
        if (in_array('results', $categories, true)) {
            $summary['results'] = LabResult::query()->whereIn('lab_order_id', LabOrder::query()->where('patient_id', $patientId)->where('status', 'released')->where('released_at', '>', now()->subYear())->select('id'))
                ->get()->map(fn (LabResult $r) => "{$r->name}: ".($r->getAttribute('result_text') ?? $r->value)." {$r->unit} ({$r->flag})")->all();
        }
        if (in_array('notes', $categories, true)) {
            $summary['notes'] = Consultation::query()->where('patient_id', $patientId)->whereNotNull('assessment')->latest()->limit(3)->get()
                ->map(fn (Consultation $c) => ($c->created_at?->format('j M Y') ?? '').': '.$c->assessment)->all();
        }

        return $summary;
    }
}
