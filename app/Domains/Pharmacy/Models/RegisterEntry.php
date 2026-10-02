<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * S5/S6 register line. Append-only: corrections are new lines.
 *
 * @property int $id
 * @property int $stock_item_id
 * @property string $schedule
 * @property string $movement
 * @property int $quantity
 * @property int $balance_after
 * @property Carbon $recorded_at
 */
class RegisterEntry extends Model
{
    public $timestamps = false;

    protected $table = 'scheduled_register';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The S5/S6 register is append-only.'));
        static::deleting(fn () => throw new LogicException('The S5/S6 register is append-only.'));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function record(StockItem $item, string $movement, int $quantity, array $details = []): ?self
    {
        if (! $item->isScheduledRegister()) {
            return null;
        }

        return static::create([
            'stock_item_id' => $item->id,
            'schedule' => strtoupper($item->schedule),
            'movement' => $movement,
            'quantity' => $quantity,
            'balance_after' => $item->onHand(),
            'recorded_at' => now(),
            ...$details,
        ]);
    }
}
