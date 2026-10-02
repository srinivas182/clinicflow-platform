<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $medicine_id
 * @property string $nappi_code
 * @property string $description
 * @property string $schedule
 * @property int $unit_price_cents
 * @property int $reorder_level
 */
class StockItem extends Model
{
    protected $guarded = ['id'];

    /**
     * @return HasMany<StockBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    /**
     * Quantity in unexpired batches.
     */
    public function onHand(): int
    {
        return (int) $this->batches()->whereDate('expiry_date', '>=', today())->sum('quantity');
    }

    public function isScheduledRegister(): bool
    {
        return in_array(strtoupper($this->schedule), ['S5', 'S6'], true);
    }
}
