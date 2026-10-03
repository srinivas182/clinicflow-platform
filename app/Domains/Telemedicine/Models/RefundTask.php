<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Models;

use App\Domains\Billing\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A refund the practice must make in its own gateway dashboard (PayFast,
 * Peach, partial Yoco) or by cash/EFT, then record here.
 *
 * @property int $id
 * @property int $payment_id
 * @property string|null $appointment_id
 * @property int $amount_cents
 * @property string $reason
 * @property Carbon $due_at
 * @property Carbon|null $done_at
 * @property string|null $reference
 * @property int $reminders_sent
 * @property-read Payment $payment
 */
class RefundTask extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'done_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
