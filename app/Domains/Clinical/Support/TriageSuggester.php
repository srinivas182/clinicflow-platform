<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Support;

use App\Domains\Clinical\Enums\TriageColour;

/**
 * Suggests a triage colour from vitals. Advisory only — the nurse decides.
 *
 * Thresholds are the demonstration rule set from the prospect's logic flow and
 * must be confirmed by the named clinical reviewer before go-live.
 */
final class TriageSuggester
{
    /**
     * @return array{colour: TriageColour, reasons: list<string>}
     */
    public static function suggest(Vitals $v): array
    {
        $red = array_filter([
            $v->spo2 !== null && $v->spo2 < 90 ? 'Oxygen saturation below 90%' : null,
            $v->systolic < 80 ? 'Systolic blood pressure below 80' : null,
            $v->pulse > 140 || $v->pulse < 40 ? 'Pulse outside 40–140' : null,
            $v->respRate !== null && $v->respRate > 30 ? 'Respiratory rate above 30' : null,
        ]);
        if ($red !== []) {
            return ['colour' => TriageColour::Red, 'reasons' => array_values($red)];
        }

        $orange = array_filter([
            $v->spo2 !== null && $v->spo2 < 92 ? 'Oxygen saturation below 92%' : null,
            $v->systolic >= 180 || $v->diastolic >= 110 ? 'Blood pressure at or above 180/110' : null,
            $v->pulse > 120 ? 'Pulse above 120' : null,
            $v->temperature >= 40.0 ? 'Temperature 40 °C or higher' : null,
            $v->respRate !== null && $v->respRate > 25 ? 'Respiratory rate above 25' : null,
            $v->glucose !== null && ($v->glucose < 3.0 || $v->glucose > 20.0) ? 'Glucose below 3 or above 20' : null,
            $v->pain !== null && $v->pain >= 8 ? 'Severe pain (8 or more)' : null,
        ]);
        if ($orange !== []) {
            return ['colour' => TriageColour::Orange, 'reasons' => array_values($orange)];
        }

        $yellow = array_filter([
            $v->temperature >= 38.5 ? 'Temperature 38.5 °C or higher' : null,
            $v->pulse > 100 ? 'Pulse above 100' : null,
            $v->systolic >= 160 ? 'Systolic blood pressure 160 or higher' : null,
            $v->pain !== null && $v->pain >= 5 ? 'Moderate pain (5 or more)' : null,
        ]);
        if ($yellow !== []) {
            return ['colour' => TriageColour::Yellow, 'reasons' => array_values($yellow)];
        }

        return ['colour' => TriageColour::Green, 'reasons' => []];
    }
}
