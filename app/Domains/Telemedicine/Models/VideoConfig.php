<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * LiveKit connection (Platform database). Drivers: "cloud" (LiveKit Cloud) or
 * "self_hosted". Only one is active; the other keeps its saved keys.
 *
 * @property int $id
 * @property string $driver
 * @property string $mode
 * @property string|null $url
 * @property string|null $api_key
 * @property string|null $api_secret
 * @property bool $enabled
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 */
class VideoConfig extends Model
{
    use CentralConnection;

    public const DRIVERS = ['cloud' => 'LiveKit Cloud', 'self_hosted' => 'Self-hosted LiveKit'];

    protected $guarded = ['id'];

    protected $hidden = ['api_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['api_secret' => 'encrypted', 'enabled' => 'boolean', 'last_tested_at' => 'datetime', 'last_test_ok' => 'boolean'];
    }

    public static function active(): ?self
    {
        return static::query()->where('enabled', true)->first();
    }

    public function isComplete(): bool
    {
        return filled($this->url) && filled($this->api_key) && filled($this->api_secret);
    }
}
