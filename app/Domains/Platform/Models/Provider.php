<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * A subscribing business: clinic, independent doctor, pharmacy or lab.
 *
 * Stored in the Platform database (`tenants` table). Each provider owns a
 * dedicated MySQL database that holds all of its patients, records and money.
 *
 * @property string $id
 * @property string $name
 * @property ProviderType $type
 * @property ProviderStatus $status
 */
class Provider extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    protected $table = 'tenants';

    /**
     * Real columns on the tenants table. Anything else is stored in `data`.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'type', 'status'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProviderType::class,
            'status' => ProviderStatus::class,
        ];
    }

    /**
     * @return HasMany<VerificationCheck, $this>
     */
    public function verificationChecks(): HasMany
    {
        return $this->hasMany(VerificationCheck::class, 'tenant_id');
    }

    /**
     * Current subscription (latest).
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'tenant_id')->latestOfMany();
    }
}
