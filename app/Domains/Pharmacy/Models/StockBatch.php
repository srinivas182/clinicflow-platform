<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $stock_item_id
 * @property string $batch_number
 * @property Carbon $expiry_date
 * @property int $quantity
 */
class StockBatch extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expiry_date' => 'date', 'quantity' => 'integer'];
    }
}
