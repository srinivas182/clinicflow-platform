<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Delivery;

use Illuminate\Database\Eloquent\Model;

/**
 * A practice's own account with a courier (provider database).
 *
 * @property int $id
 * @property string $driver
 * @property string|null $account_ref
 * @property array<string, string>|null $credentials
 * @property bool $enabled
 */
class CourierAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'enabled' => 'boolean'];
    }
}
