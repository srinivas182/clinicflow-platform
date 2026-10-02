<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $invoice_id
 * @property PaymentMethod $method
 * @property int $amount_cents
 * @property int $refunded_cents
 * @property PaymentStatus $status
 * @property string|null $reference
 * @property string|null $gateway
 * @property string|null $gateway_mode
 * @property string|null $checkout_token
 * @property string|null $gateway_reference
 * @property-read Invoice $invoice
 */
class Payment extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['method' => PaymentMethod::class, 'status' => PaymentStatus::class, 'amount_cents' => 'integer', 'refunded_cents' => 'integer'];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function refundableCents(): int
    {
        return $this->status === PaymentStatus::Succeeded ? $this->amount_cents - $this->refunded_cents : 0;
    }
}
