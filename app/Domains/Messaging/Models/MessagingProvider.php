<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use App\Domains\Messaging\Enums\MessagingDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A platform SMS or email account (Platform database). Credentials encrypted.
 *
 * @property int $id
 * @property MessagingDriver $driver
 * @property string $channel
 * @property string $mode
 * @property bool $enabled
 * @property bool $is_default
 * @property array<string, string>|null $credentials
 * @property string|null $sender
 * @property list<string>|null $test_recipients
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 */
class MessagingProvider extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => MessagingDriver::class,
            'enabled' => 'boolean',
            'is_default' => 'boolean',
            'credentials' => 'encrypted:array',
            'test_recipients' => 'array',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    public static function activeFor(string $channel): ?self
    {
        return static::query()->where('channel', $channel)->where('enabled', true)->orderByDesc('is_default')->orderBy('id')->first();
    }

    public function isTestMode(): bool
    {
        return $this->mode !== 'live';
    }
}
