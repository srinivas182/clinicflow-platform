<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $payment_id
 * @property int $amount_cents
 * @property string $reason
 * @property string $status
 * @property string|null $reference
 */
class Refund extends Model
{
    protected $guarded = ['id'];
}
