<?php

declare(strict_types=1);

namespace App\Domains\Documents\Support;

use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;

/**
 * The provider's branding and registration details used on every document.
 */
final class PracticeData
{
    public const FIELDS = ['address', 'phone', 'bhf', 'vat_number', 'colour'];

    /**
     * @return array<string, string>
     */
    public static function get(): array
    {
        $provider = tenant();
        $data = ['name' => $provider instanceof Provider ? $provider->name : ''];

        foreach (self::FIELDS as $field) {
            $value = Setting::get('branding', $field, $field === 'colour' ? '#0F7C74' : '');
            $data[$field] = is_scalar($value) ? (string) $value : '';
        }

        return $data;
    }
}
