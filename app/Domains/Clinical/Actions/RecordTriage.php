<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Actions;

use App\Domains\Clinical\Enums\TriageColour;
use App\Domains\Clinical\Events\RedTriageAlert;
use App\Domains\Clinical\Models\TriageRecord;
use App\Domains\Clinical\Support\TriageSuggester;
use App\Domains\Clinical\Support\Vitals;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves vitals and the nurse's colour, then moves the patient to the doctor
 * queue. Red alerts every doctor on shift.
 */
class RecordTriage
{
    public function __construct(private readonly TransitionVisit $transition) {}

    public function handle(Visit $visit, Vitals $vitals, TriageColour $colour, ?string $notes = null, ?User $nurse = null): TriageRecord
    {
        if ($visit->stage !== VisitStage::Triage) {
            throw ValidationException::withMessages(['visit' => 'This patient is not waiting for triage.']);
        }

        $errors = array_filter([
            'bp_systolic' => $vitals->systolic < 40 || $vitals->systolic > 300 ? 'Check the systolic value (40–300).' : null,
            'bp_diastolic' => $vitals->diastolic < 20 || $vitals->diastolic > 200 || $vitals->diastolic >= $vitals->systolic ? 'Check the diastolic value.' : null,
            'pulse' => $vitals->pulse < 20 || $vitals->pulse > 250 ? 'Check the pulse (20–250).' : null,
            'temperature' => $vitals->temperature < 30.0 || $vitals->temperature > 45.0 ? 'Check the temperature (30–45 °C).' : null,
            'spo2' => $vitals->spo2 !== null && ($vitals->spo2 < 50 || $vitals->spo2 > 100) ? 'Check the oxygen saturation (50–100).' : null,
            'pain_score' => $vitals->pain !== null && ($vitals->pain < 0 || $vitals->pain > 10) ? 'Pain score is 0–10.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $suggested = TriageSuggester::suggest($vitals)['colour'];

        return DB::transaction(function () use ($visit, $vitals, $colour, $suggested, $notes, $nurse): TriageRecord {
            $record = TriageRecord::create([
                'visit_id' => $visit->id,
                'bp_systolic' => $vitals->systolic,
                'bp_diastolic' => $vitals->diastolic,
                'pulse' => $vitals->pulse,
                'temperature' => $vitals->temperature,
                'spo2' => $vitals->spo2,
                'resp_rate' => $vitals->respRate,
                'glucose' => $vitals->glucose,
                'weight_kg' => $vitals->weightKg,
                'pain_score' => $vitals->pain,
                'suggested_colour' => $suggested,
                'colour' => $colour,
                'notes' => $notes,
                'nurse_staff_id' => $nurse?->id,
                'created_at' => now(),
            ]);

            $visit->forceFill(['triage_colour' => $colour->value])->save();
            $this->transition->handle($visit, VisitStage::Doctor, $nurse);

            activity('clinical')->performedOn($visit)->causedBy($nurse)
                ->withProperties(['colour' => $colour->value, 'suggested' => $suggested->value])
                ->log('Triage recorded');

            if ($colour === TriageColour::Red) {
                event(new RedTriageAlert((string) tenant()?->getTenantKey(), $visit->id, $visit->ticket));
            }

            return $record;
        });
    }
}
