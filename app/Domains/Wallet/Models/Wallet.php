<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Models;

use App\Domains\Platform\Models\Provider;
use App\Domains\Wallet\Support\WalletSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A provider's prepaid telemedicine wallet (Platform database).
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $balance_cents
 * @property int $reserved_cents
 * @property int|null $threshold_cents
 * @property bool $auto_topup
 * @property int|null $auto_topup_pack_cents
 * @property Carbon|null $low_balance_notified_at
 * @property-read Provider $provider
 */
class Wallet extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['auto_topup' => 'boolean', 'low_balance_notified_at' => 'datetime', 'balance_cents' => 'integer', 'reserved_cents' => 'integer'];
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'tenant_id');
    }

    public static function for(string $tenantId): self
    {
        return static::query()->firstOrCreate(['tenant_id' => $tenantId], ['balance_cents' => 0, 'reserved_cents' => 0]);
    }

    public function availableCents(): int
    {
        return $this->balance_cents - $this->reserved_cents;
    }

    public function thresholdCents(): int
    {
        return $this->threshold_cents ?? WalletSettings::thresholdCents();
    }

    /**
     * Online consult slots are shown to patients only above the threshold.
     */
    public function acceptsOnlineBookings(): bool
    {
        return $this->availableCents() >= $this->thresholdCents();
    }
}
