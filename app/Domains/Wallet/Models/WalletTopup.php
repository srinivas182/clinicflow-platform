<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property int $wallet_id
 * @property int $amount_cents
 * @property int $bonus_cents
 * @property int $vat_cents
 * @property string $status
 * @property string $method
 * @property string $checkout_token
 * @property string|null $gateway
 * @property string|null $gateway_reference
 * @property Carbon|null $paid_at
 * @property-read Wallet $wallet
 */
class WalletTopup extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
