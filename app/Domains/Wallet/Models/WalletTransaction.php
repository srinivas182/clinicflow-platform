<?php

declare(strict_types=1);

namespace App\Domains\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Append-only wallet statement line.
 *
 * @property int $id
 * @property int $wallet_id
 * @property string $type
 * @property int $amount_cents
 * @property int $balance_after_cents
 * @property string|null $reference
 * @property string $description
 * @property Carbon $created_at
 */
class WalletTransaction extends Model
{
    use CentralConnection;

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Wallet transactions are append-only.'));
        static::deleting(fn () => throw new LogicException('Wallet transactions are append-only.'));
    }
}
