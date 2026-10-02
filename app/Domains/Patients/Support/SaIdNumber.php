<?php

declare(strict_types=1);

namespace App\Domains\Patients\Support;

use App\Domains\Patients\Enums\Sex;
use Carbon\CarbonImmutable;

/**
 * South African ID number: YYMMDD SSSS C A Z (13 digits, Luhn check digit).
 */
final class SaIdNumber
{
    private function __construct(private readonly string $digits) {}

    public static function tryParse(string $value, ?CarbonImmutable $today = null): ?self
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        if (strlen($digits) !== 13 || ! self::luhnValid($digits)) {
            return null;
        }

        $id = new self($digits);

        return $id->dateOfBirth($today) === null ? null : $id;
    }

    public static function luhnValid(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    public function value(): string
    {
        return $this->digits;
    }

    public function dateOfBirth(?CarbonImmutable $today = null): ?CarbonImmutable
    {
        $today ??= CarbonImmutable::today();
        $yy = (int) substr($this->digits, 0, 2);
        $mm = (int) substr($this->digits, 2, 2);
        $dd = (int) substr($this->digits, 4, 2);

        $century = ($yy + 2000) > (int) $today->format('Y') ? 1900 : 2000;

        if (! checkdate($mm, $dd, $century + $yy)) {
            return null;
        }

        return CarbonImmutable::create($century + $yy, $mm, $dd)?->startOfDay();
    }

    public function sex(): Sex
    {
        return (int) substr($this->digits, 6, 4) >= 5000 ? Sex::Male : Sex::Female;
    }

    public function isCitizen(): bool
    {
        return $this->digits[10] === '0';
    }

    /**
     * Keyed hash used to find a patient by ID number without storing it in clear.
     */
    public function lookupHash(): string
    {
        return hash_hmac('sha256', $this->digits, (string) config('app.key'));
    }
}
