<?php

declare(strict_types=1);

namespace App\Domains\Billing\Support;

use App\Domains\Billing\Enums\LineKind;
use App\Domains\Platform\Models\Setting;

/**
 * Practice VAT (Setting group "vat"). Prices are VAT-inclusive; a VAT-registered
 * practice issues tax invoices showing the VAT portion; a practice that is not
 * registered charges no VAT. Zero-rated or exempt line kinds are set by the
 * practice on its accountant's advice.
 */
final class Vat
{
    public static function registered(): bool
    {
        return (bool) Setting::get('vat', 'registered', false);
    }

    public static function number(): ?string
    {
        $n = Setting::get('vat', 'number');

        return is_string($n) && $n !== '' ? $n : null;
    }

    public static function rate(): float
    {
        return (float) (Setting::get('vat', 'rate', (float) config('clinicflow.payments.vat_rate', 0.15) * 100) ?? 15);
    }

    /**
     * VAT contained in a VAT-inclusive amount.
     */
    public static function inclusive(int $cents, ?LineKind $kind = null): int
    {
        if (! self::registered() || ($kind !== null && in_array($kind->value, (array) Setting::get('vat', 'zero_rated_kinds', []), true))) {
            return 0;
        }

        return (int) round($cents * self::rate() / (100 + self::rate()));
    }

    /**
     * VAT added on top of a VAT-exclusive amount (supplier purchases).
     */
    public static function exclusive(int $cents): int
    {
        return (int) round($cents * self::rate() / 100);
    }
}
