<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Care;

use App\Domains\Patients\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pregnancy tracker: due date from the last menstrual period (Naegele, +280 days),
 * the antenatal contact schedule (DEMO default, to be confirmed) and risk flags.
 */
class Pregnancy
{
    public const CONTACT_WEEKS = [12, 20, 26, 30, 34, 36, 38, 40];

    public function start(Patient $patient, string $lmp): int
    {
        $date = CarbonImmutable::parse($lmp);
        if ($date->isFuture() || $date->lt(now()->subWeeks(44))) {
            throw ValidationException::withMessages(['lmp' => 'Enter the first day of the last menstrual period within the last 44 weeks.']);
        }
        if (DB::table('pregnancies')->where('patient_id', $patient->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['lmp' => 'This patient already has an active pregnancy record.']);
        }

        return (int) DB::table('pregnancies')->insertGetId(['patient_id' => $patient->id, 'lmp' => $date->toDateString(), 'edd' => $date->addDays(280)->toDateString(), 'risk_flags' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function recordVisit(int $pregnancyId, string $date, ?string $bp, ?float $weight, ?int $fundalHeight, ?string $notes, int $by): void
    {
        $preg = DB::table('pregnancies')->where('id', $pregnancyId)->first();
        abort_if($preg === null, 404);
        $weeks = (int) floor(CarbonImmutable::parse((string) $preg->lmp)->diffInDays(CarbonImmutable::parse($date)) / 7);
        DB::table('antenatal_visits')->insert(['pregnancy_id' => $pregnancyId, 'visit_date' => $date, 'gestation_weeks' => $weeks, 'bp' => $bp, 'weight_kg' => $weight, 'fundal_height_cm' => $fundalHeight, 'notes' => $notes, 'recorded_by' => $by, 'created_at' => now(), 'updated_at' => now()]);

        $flags = (array) json_decode((string) $preg->risk_flags, true);
        if ($bp !== null && preg_match('/^(\d{2,3})\/(\d{2,3})$/', $bp, $m) === 1 && ((int) $m[1] >= 140 || (int) $m[2] >= 90)) {
            $flags[] = "High blood pressure ({$bp}) at {$weeks} weeks";
        }
        DB::table('pregnancies')->where('id', $pregnancyId)->update(['risk_flags' => json_encode(array_values(array_unique($flags))), 'updated_at' => now()]);
    }

    /**
     * @return array{edd: string, weeks: int, contacts: list<array{week: int, date: string, done: bool}>, risks: list<string>}|null
     */
    public function summary(Patient $patient): ?array
    {
        $preg = DB::table('pregnancies')->where('patient_id', $patient->id)->where('status', 'active')->first();
        if ($preg === null) {
            return null;
        }
        $lmp = CarbonImmutable::parse((string) $preg->lmp);
        $visited = DB::table('antenatal_visits')->where('pregnancy_id', $preg->id)->pluck('gestation_weeks')->map(fn ($w) => (int) $w)->all();

        return [
            'edd' => (string) $preg->edd,
            'weeks' => (int) floor($lmp->diffInDays(now()) / 7),
            'contacts' => array_map(fn (int $w) => ['week' => $w, 'date' => $lmp->addWeeks($w)->toDateString(), 'done' => collect($visited)->contains(fn ($v) => abs($v - $w) <= 2)], self::CONTACT_WEEKS),
            'risks' => array_values((array) json_decode((string) $preg->risk_flags, true)),
        ];
    }
}
