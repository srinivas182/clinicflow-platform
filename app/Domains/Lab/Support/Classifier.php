<?php

declare(strict_types=1);

namespace App\Domains\Lab\Support;

use App\Domains\Lab\Models\CatalogRange;
use App\Domains\Lab\Models\CatalogTest;
use App\Domains\Patients\Models\Patient;
use Illuminate\Validation\ValidationException;

/**
 * Turns an entered value into a flag using the reference range that matches
 * the patient's sex and age. The system classifies; the lab can only raise.
 */
final class Classifier
{
    public static function rangeFor(CatalogTest $test, Patient $patient): ?CatalogRange
    {
        $months = (int) $patient->date_of_birth->diffInMonths(now());
        $sex = $patient->sex?->value;

        return $test->ranges()->where('age_min_months', '<=', $months)->where('age_max_months', '>=', $months)
            ->where(fn ($q) => $q->where('sex', $sex)->orWhereNull('sex'))
            ->orderByRaw('sex is null')->first();
    }

    /**
     * Validates a numeric value (plausible limits, decimals) and returns its flag.
     *
     * @return array{value: float, flag: string}
     */
    public static function numeric(CatalogTest $test, ?CatalogRange $range, string $field, mixed $raw): array
    {
        if (! is_numeric($raw)) {
            throw ValidationException::withMessages([$field => "Enter a number for {$test->name}."]);
        }
        $value = round((float) $raw, $test->decimals);
        if (($test->plausible_min !== null && $value < (float) $test->plausible_min) || ($test->plausible_max !== null && $value > (float) $test->plausible_max)) {
            throw ValidationException::withMessages([$field => "{$value} {$test->unit} is not a possible {$test->name} result. Check the value."]);
        }

        $flag = match (true) {
            $range?->critical_low !== null && $value <= (float) $range->critical_low => 'critical_low',
            $range?->critical_high !== null && $value >= (float) $range->critical_high => 'critical_high',
            $range?->ref_low !== null && $value < (float) $range->ref_low => 'low',
            $range?->ref_high !== null && $value > (float) $range->ref_high => 'high',
            default => 'normal',
        };

        return ['value' => $value, 'flag' => $flag];
    }

    /**
     * Order classification: the worst result decides. Unflagged (text/PDF-only) results make it unclassified.
     *
     * @param  list<?string>  $flags
     */
    public static function order(array $flags): string
    {
        if ($flags === [] || in_array(null, $flags, true)) {
            return in_array('critical_low', $flags, true) || in_array('critical_high', $flags, true) || in_array('critical', $flags, true) ? 'critical' : 'unclassified';
        }
        foreach ($flags as $f) {
            if (str_starts_with((string) $f, 'critical')) {
                return 'critical';
            }
        }

        return array_diff($flags, ['normal']) === [] ? 'normal' : 'abnormal';
    }

    public static function label(?CatalogRange $range): string
    {
        return match (true) {
            $range === null => '',
            $range->ref_low !== null && $range->ref_high !== null => "{$range->ref_low}–{$range->ref_high}",
            $range->ref_high !== null => "< {$range->ref_high}",
            $range->ref_low !== null => "> {$range->ref_low}",
            default => '',
        };
    }
}
