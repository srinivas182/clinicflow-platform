<?php

use App\Domains\Clinical\Enums\TriageColour;
use App\Domains\Clinical\Support\TriageSuggester;
use App\Domains\Clinical\Support\Vitals;

it('suggests a colour from vitals', function (Vitals $vitals, TriageColour $expected): void {
    expect(TriageSuggester::suggest($vitals)['colour'])->toBe($expected);
})->with([
    'low oxygen is red' => [new Vitals(120, 80, 90, 37.0, spo2: 88), TriageColour::Red],
    'shock is red' => [new Vitals(75, 40, 130, 36.5), TriageColour::Red],
    'BP 172/112 is orange' => [new Vitals(172, 112, 96, 37.2), TriageColour::Orange],
    'severe pain is orange' => [new Vitals(130, 85, 90, 37.0, pain: 9), TriageColour::Orange],
    'fever is yellow' => [new Vitals(125, 80, 95, 38.8), TriageColour::Yellow],
    'normal is green' => [new Vitals(128, 82, 76, 36.8, spo2: 98, pain: 2), TriageColour::Green],
]);

it('explains why it suggested a colour', function (): void {
    expect(TriageSuggester::suggest(new Vitals(185, 100, 96, 37.2))['reasons'])->toBe(['Blood pressure at or above 180/110']);
});
