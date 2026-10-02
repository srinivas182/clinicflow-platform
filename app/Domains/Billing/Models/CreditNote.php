<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $number
 * @property string $invoice_id
 * @property int $amount_cents
 * @property string $reason
 * @property int|null $issued_by
 */
class CreditNote extends Model
{
    protected $guarded = ['id'];
}
