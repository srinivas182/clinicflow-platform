<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Actions;

use App\Domains\Clinical\Models\Allergy;
use App\Domains\Prescribing\Contracts\DrugDatabase;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Prescribing\Models\PrescriptionItem;
use App\Domains\Prescribing\Support\SafetyIssue;
use App\Domains\Prescribing\Support\ScheduleRules;

/**
 * Safety checks on a draft script:
 *  - allergy: any ingredient or class matches an active allergy → must fix;
 *  - schedule: S6 repeats, S3/S4 more than five repeats → must fix;
 *  - interaction with another item or the patient's current medicines → reason needed;
 *  - duplicate active ingredient → reason needed.
 */
class CheckPrescriptionSafety
{
    public function __construct(private readonly DrugDatabase $drugs) {}

    /**
     * @return list<SafetyIssue>
     */
    public function handle(Prescription $prescription): array
    {
        $issues = [];
        $allergies = Allergy::query()->where('patient_id', $prescription->patient_id)->where('status', 'active')
            ->pluck('substance')->map(fn ($s) => strtolower(trim((string) $s)))->all();

        $items = $prescription->items()->get();
        $ingredients = [];
        foreach ($items as $item) {
            $medicine = $this->drugs->find($item->medicine_id);
            $ingredients[$item->id] = $medicine?->ingredients ?? [];
        }

        $current = $this->currentMedicines($prescription);

        foreach ($items as $item) {
            $medicine = $this->drugs->find($item->medicine_id);
            if ($medicine === null) {
                $issues[] = new SafetyIssue($item->id, 'unknown', 'block', "{$item->description} is not in the medicine database.");

                continue;
            }

            $terms = array_merge($medicine->ingredients, $medicine->allergy_classes);
            foreach ($allergies as $allergy) {
                if (in_array($allergy, $terms, true)) {
                    $issues[] = new SafetyIssue($item->id, 'allergy', 'block', "Allergy: the patient is allergic to {$allergy}. Choose another medicine.");
                }
            }

            $max = ScheduleRules::maxRepeats($medicine->schedule);
            if ($max !== null && $item->repeats > $max) {
                $issues[] = new SafetyIssue($item->id, 'schedule', 'block', $max === 0
                    ? "{$medicine->schedule} medicines cannot have repeats."
                    : "{$medicine->schedule} medicines allow at most {$max} repeats.");
            }

            foreach ($items as $other) {
                if ($other->id <= $item->id) {
                    continue;
                }
                $shared = array_intersect($ingredients[$item->id], $ingredients[$other->id]);
                if ($shared !== []) {
                    $issues[] = new SafetyIssue($other->id, 'duplicate', 'override', 'Duplicate: '.implode(', ', $shared)." is already on this script ({$item->description}).");
                }
                foreach ($this->drugs->interactions($ingredients[$item->id], $ingredients[$other->id]) as $hit) {
                    $issues[] = new SafetyIssue($other->id, 'interaction', 'override', "Interaction ({$hit['severity']}): {$hit['pair']}. {$hit['message']}");
                }
            }

            foreach ($current as $existing) {
                $shared = array_intersect($ingredients[$item->id], $existing['ingredients']);
                if ($shared !== []) {
                    $issues[] = new SafetyIssue($item->id, 'duplicate', 'override', 'Duplicate: the patient already takes '.$existing['description'].'.');
                }
                foreach ($this->drugs->interactions($ingredients[$item->id], $existing['ingredients']) as $hit) {
                    $issues[] = new SafetyIssue($item->id, 'interaction', 'override', "Interaction ({$hit['severity']}) with current {$existing['description']}: {$hit['message']}");
                }
            }
        }

        return $issues;
    }

    /**
     * True when nothing blocks signing and every override has a reason.
     *
     * @param  list<SafetyIssue>  $issues
     */
    public function canSign(Prescription $prescription, array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->level === 'block') {
                return false;
            }
            $reason = PrescriptionItem::query()->whereKey($issue->itemId)->value('override_reason');
            if (! is_string($reason) || trim($reason) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Medicines from the patient's other signed, current scripts (last 90 days).
     *
     * @return list<array{description: string, ingredients: list<string>}>
     */
    private function currentMedicines(Prescription $prescription): array
    {
        $current = [];
        $scripts = Prescription::query()->with('items')->where('patient_id', $prescription->patient_id)
            ->where('status', Prescription::SIGNED)->where('consultation_id', '!=', $prescription->consultation_id)
            ->where('signed_at', '>', now()->subDays(90))->get();

        foreach ($scripts as $script) {
            foreach ($script->items as $item) {
                $current[] = ['description' => $item->description, 'ingredients' => $this->drugs->find($item->medicine_id)?->ingredients ?? []];
            }
        }

        return $current;
    }
}
