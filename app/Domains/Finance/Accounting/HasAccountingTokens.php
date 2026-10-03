<?php

declare(strict_types=1);

namespace App\Domains\Finance\Accounting;

use Illuminate\Support\Carbon;

/**
 * Shared shape of a provider's and the platform's accounting connection.
 *
 * @property int $id
 * @property AccountingDriver $driver
 * @property bool $enabled
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property string|null $org_id
 * @property string $auto_export
 * @property array<string, string>|null $account_map
 * @property Carbon|null $exported_until
 * @property string|null $last_error
 */
trait HasAccountingTokens
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => AccountingDriver::class, 'enabled' => 'boolean', 'access_token' => 'encrypted', 'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime', 'account_map' => 'array', 'exported_until' => 'date',
        ];
    }
}
