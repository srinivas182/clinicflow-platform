<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int $medicine_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_cost_cents
 * @property int $received_quantity
 */
class PurchaseOrderLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];
}
