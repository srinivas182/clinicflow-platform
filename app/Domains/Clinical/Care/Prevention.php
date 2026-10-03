<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Care;

use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Patients\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Preventive recalls and immunisations. Defaults are DEMO starting points,
 * marked "not reviewed" until the clinical reviewer confirms them.
 */
class Prevention
{
    /** [name, reason, sex, age min, age max, interval months] */
    public const RECALL_DEFAULTS = [
        ['Cervical screening', 'Pap smear', 'female', 25, 65, 36],
        ['Mammogram', 'mammogram', 'female', 40, 74, 24],
        ['Flu vaccine', 'annual flu vaccine', null, 65, 120, 12],
        ['Blood pressure check', 'blood pressure check', null, 40, 120, 12],
        ['Diabetes screening', 'blood sugar check', null, 45, 120, 36],
    ];

    /** [vaccine, dose, age in weeks] — South African childhood schedule (to be confirmed). */
    public const IMMUNISATION_DEFAULTS = [
        ['BCG', 'Birth', 0], ['OPV', 'Birth', 0], ['Hexavalent', '1st', 6], ['Rotavirus', '1st', 6], ['PCV', '1st', 6],
        ['Hexavalent', '2nd', 10], ['Hexavalent', '3rd', 14], ['Rotavirus', '2nd', 14], ['PCV', '2nd', 14],
        ['Measles', '1st', 26], ['PCV', '3rd', 39], ['Measles', '2nd', 52], ['Hexavalent', '4th', 78], ['Td', '6 years', 312], ['Td', '12 years', 624],
    ];

    public function ensureDefaults(): void
    {
        if (DB::table('recall_rules')->doesntExist()) {
            foreach (self::RECALL_DEFAULTS as [$name, $reason, $sex, $min, $max, $months]) {
                DB::table('recall_rules')->insert(['name' => $name, 'reason' => $reason, 'sex' => $sex, 'age_min' => $min, 'age_max' => $max, 'interval_months' => $months]);
            }
        }
        if (DB::table('immunisation_schedule')->doesntExist()) {
            foreach (self::IMMUNISATION_DEFAULTS as [$vaccine, $dose, $weeks]) {
                DB::table('immunisation_schedule')->insert(['vaccine' => $vaccine, 'dose' => $dose, 'age_weeks' => $weeks]);
            }
        }
    }

    /**
     * Sends due recall reminders once; patients who opted out of reminders are skipped by the messaging rules.
     */
    public function sendDueRecalls(): int
    {
        $this->ensureDefaults();
        $sent = 0;
        foreach (DB::table('recall_rules')->where('active', true)->get() as $rule) {
            Patient::query()->whereNotNull('cell')->when($rule->sex !== null, fn ($q) => $q->where('sex', $rule->sex))
                ->whereDate('date_of_birth', '<=', now()->subYears((int) $rule->age_min))->whereDate('date_of_birth', '>', now()->subYears((int) $rule->age_max + 1))
                ->each(function (Patient $p) use ($rule, &$sent): void {
                    $lastDone = DB::table('patient_recalls')->where('patient_id', $p->id)->where('recall_rule_id', $rule->id)->max('done_at');
                    $dueOn = $lastDone === null ? CarbonImmutable::today() : CarbonImmutable::parse((string) $lastDone)->addMonths((int) $rule->interval_months)->startOfDay();
                    if ($dueOn->isFuture() || DB::table('patient_recalls')->where('patient_id', $p->id)->where('recall_rule_id', $rule->id)->whereDate('due_on', $dueOn)->whereNotNull('sent_at')->exists()) {
                        return;
                    }
                    $ok = app(SendMessage::class)->template('recall.reminder', 'sms', (string) $p->cell, ['patient' => $p->first_names, 'reason' => (string) $rule->reason]);
                    DB::table('patient_recalls')->updateOrInsert(['patient_id' => $p->id, 'recall_rule_id' => $rule->id, 'due_on' => $dueOn->toDateString()], ['sent_at' => now()]);
                    $sent += $ok ? 1 : 0;
                });
        }

        return $sent;
    }

    public function markRecallDone(Patient $patient, int $ruleId): void
    {
        DB::table('patient_recalls')->updateOrInsert(['patient_id' => $patient->id, 'recall_rule_id' => $ruleId, 'due_on' => today()->toDateString()], ['done_at' => now()]);
    }

    public function recordImmunisation(Patient $patient, string $vaccine, string $dose, string $givenOn, ?string $batch, int $by): void
    {
        if (CarbonImmutable::parse($givenOn)->isFuture()) {
            throw ValidationException::withMessages(['given_on' => 'The date cannot be in the future.']);
        }
        DB::table('immunisations')->insert(['patient_id' => $patient->id, 'vaccine' => $vaccine, 'dose' => $dose, 'given_on' => $givenOn, 'batch' => $batch, 'given_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return list<array{vaccine: string, dose: string, due: string, overdue: bool}>
     */
    public function immunisationsDue(Patient $patient): array
    {
        $this->ensureDefaults();
        $ageWeeks = (int) $patient->date_of_birth->diffInWeeks(now());
        $given = DB::table('immunisations')->where('patient_id', $patient->id)->get()->map(fn ($i) => $i->vaccine.'|'.$i->dose)->all();
        $due = [];
        foreach (DB::table('immunisation_schedule')->orderBy('age_weeks')->get() as $item) {
            if (in_array($item->vaccine.'|'.$item->dose, $given, true) || (int) $item->age_weeks > $ageWeeks + 4) {
                continue;
            }
            $dueOn = $patient->date_of_birth->copy()->addWeeks((int) $item->age_weeks);
            $due[] = ['vaccine' => (string) $item->vaccine, 'dose' => (string) $item->dose, 'due' => $dueOn->toDateString(), 'overdue' => $dueOn->lt(today())];
        }

        return $due;
    }
}
