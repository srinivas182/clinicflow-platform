<?php

declare(strict_types=1);

namespace App\Domains\Finance\Accounting;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Clinic Flow's registered app with each accounting vendor (super admin).
 *
 * @property int $id
 * @property AccountingDriver $driver
 * @property bool $offered
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $region
 */
class AccountingApp extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    protected $hidden = ['client_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['driver' => AccountingDriver::class, 'offered' => 'boolean', 'client_secret' => 'encrypted'];
    }

    public function isConfigured(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }
}
