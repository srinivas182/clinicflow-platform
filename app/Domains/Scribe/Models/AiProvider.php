<?php

declare(strict_types=1);

namespace App\Domains\Scribe\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A speech-to-text or note-writing provider managed by the super admin (platform database).
 *
 * @property int $id
 * @property string $driver
 * @property string $kind
 * @property bool $enabled
 * @property array<string, string>|null $credentials
 * @property int $cost_per_minute_millicents
 */
class AiProvider extends Model
{
    public const DRIVERS = ['deepgram' => 'speech', 'azure' => 'speech', 'anthropic' => 'notes'];

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return (string) config('tenancy.database.central_connection');
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'credentials' => 'encrypted:array'];
    }

    public static function active(string $kind): ?self
    {
        return self::query()->where('kind', $kind)->where('enabled', true)->orderBy('id')->first();
    }

    public function credential(string $key, string $default = ''): string
    {
        return (string) ($this->credentials[$key] ?? $default);
    }
}
