<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use Illuminate\Database\Eloquent\Model;

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

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('group', $group)->where('key', $key)->first();

        return $row === null ? $default : $row->value;
    }

    public static function put(string $group, string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['group' => $group, 'key' => $key], ['value' => $value]);
    }
}
