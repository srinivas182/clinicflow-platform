<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\GatewayMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A provider's own merchant account (provider database).
 *
 * @property int $id
 * @property Gateway $gateway
 * @property GatewayMode $mode
 * @property bool $enabled
 * @property bool $is_default
 * @property array<string, string>|null $credentials
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 */
class GatewayConfig extends Model
{
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
            'credentials' => 'encrypted:array',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }
}
