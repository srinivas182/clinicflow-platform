<?php

declare(strict_types=1);

namespace App\Domains\Translation\Support;

use App\Domains\Translation\Models\Language;

/**
 * Which languages are switched on (Admin → Languages), and the language to use for a patient:
 * their preferred language if it is on, otherwise English.
 */
final class Languages
{
    /** @return list<string> */
    public static function enabled(): array
    {
        $codes = Language::query()->where('enabled', true)->pluck('code')->map(fn ($c) => (string) $c)->values()->all();

        return array_values(in_array('en', $codes, true) ? $codes : ['en', ...$codes]);
    }

    /** @return list<array{code: string, name: string}> */
    public static function options(): array
    {
        $options = [];
        foreach (Language::query()->where('enabled', true)->orderByRaw("code = 'en' desc")->orderBy('name')->get(['code', 'name']) as $l) {
            $options[] = ['code' => (string) $l->code, 'name' => (string) $l->name];
        }

        return $options;
    }

    public static function for(?string $preferred): string
    {
        return is_string($preferred) && in_array($preferred, self::enabled(), true) ? $preferred : 'en';
    }
}
