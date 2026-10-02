<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\GatewayMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * The platform's own merchant accounts (subscription billing) and the list of
 * gateways offered to providers (Platform database).
 *
 * @property int $id
 * @property Gateway $gateway
 * @property GatewayMode $mode
 * @property bool $enabled
 * @property bool $is_default
 * @property bool $offered_to_providers
 * @property array<string, string>|null $credentials
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 */
class PlatformGatewayConfig extends Model
{
    use CentralConnection;

    protected $table = 'payment_gateway_configs';

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway' => Gateway::class,
            'mode' => GatewayMode::class,
            'enabled' => 'boolean',
            'is_default' => 'boolean',
            'offered_to_providers' => 'boolean',
            'credentials' => 'encrypted:array',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    /**
     * Gateways providers may connect. All four unless the super admin switches one off.
     *
     * @return list<string>
     */
    public static function offeredGateways(): array
    {
        $off = static::query()->where('offered_to_providers', false)->pluck('gateway')
            ->map(fn ($g) => $g instanceof Gateway ? $g->value : (string) $g)->all();

        return array_values(array_diff(array_map(fn (Gateway $g) => $g->value, Gateway::cases()), $off));
    }
}
