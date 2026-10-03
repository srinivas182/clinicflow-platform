<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Support;

use App\Domains\Platform\Models\Setting;

/**
 * Practice rules for online consults (Setting group "telemedicine").
 * The doctor no-show refund time is fixed to protect patients.
 */
final class TeleSettings
{
    public const DOCTOR_NO_SHOW_MINUTES = 10;

    public const DEFAULTS = [
        'hold_minutes' => 10,
        'grace_minutes' => 3,
        'cancel_cutoff_minutes' => 120,
        'followup_days' => 3,
        'extension_minutes' => 15,
        'buffer_minutes' => 0,
    ];

    public static function get(string $key): int
    {
        return (int) (Setting::get('telemedicine', $key, self::DEFAULTS[$key] ?? 0) ?? (self::DEFAULTS[$key] ?? 0));
    }

    /**
     * @return array<string, int>
     */
    public static function all(): array
    {
        return array_map(fn (string $k) => self::get($k), array_combine(array_keys(self::DEFAULTS), array_keys(self::DEFAULTS)));
    }
}
