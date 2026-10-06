<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Provider-level setting (provider database), e.g. billing.payment_timing.
 *
 * @property int $id
 * @property string $group
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /**
     * Cached per practice for 10 minutes (shared through the cache store, so every server sees changes);
     * put() clears the entry at once.
     */
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $cached = Cache::remember(self::cacheKey($group, $key), 600, function () use ($group, $key): array {
            $row = static::query()->where('group', $group)->where('key', $key)->first();

            return ['found' => $row !== null, 'value' => $row?->value];
        });

        return $cached['found'] ? $cached['value'] : $default;
    }

    public static function put(string $group, string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['group' => $group, 'key' => $key], ['value' => $value]);
        Cache::forget(self::cacheKey($group, $key));
    }

    private static function cacheKey(string $group, string $key): string
    {
        return 'setting:'.(tenant('id') ?? 'platform').':'.$group.':'.$key;
    }
}
