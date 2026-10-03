<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Status: draft → sent → partial → received; or cancelled.
 *
 * @property int $id
 * @property string $number
 * @property int $supplier_id
 * @property string $status
 * @property int $total_cents
 * @property int $vat_cents
 * @property int|null $ordered_by
 * @property Carbon|null $sent_at
 * @property-read Supplier $supplier
 */
class PurchaseOrder extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
