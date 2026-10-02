<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\LineKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $invoice_id
 * @property LineKind $kind
 * @property string|null $code
 * @property string $description
 * @property int $quantity
 * @property int $unit_cents
 * @property int $total_cents
 * @property Carbon|null $locked_at
 */
class InvoiceLine extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => LineKind::class, 'locked_at' => 'datetime'];
    }
}
