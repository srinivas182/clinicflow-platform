<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Patients\Models\Patient;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Models\Visit;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $number
 * @property string $patient_id
 * @property string|null $visit_id
 * @property PayerType $payer_type
 * @property InvoiceStatus $status
 * @property int $total_cents
 * @property int $paid_cents
 * @property bool $needs_review
 * @property string|null $review_note
 * @property-read Patient $patient
 * @property-read Visit|null $visit
 */
class Invoice extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'payer_type' => PayerType::class,
            'total_cents' => 'integer',
            'paid_cents' => 'integer',
            'needs_review' => 'boolean',
        ];
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Visit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function balanceCents(): int
    {
        return max(0, $this->total_cents - $this->paid_cents);
    }

    /**
     * Recalculate totals and status from lines and successful payments.
     */
    public function recalculate(): void
    {
        $this->total_cents = (int) $this->lines()->sum('total_cents');
        $this->paid_cents = (int) $this->payments()->where('status', 'succeeded')->sum('amount_cents')
            - (int) $this->payments()->where('status', 'succeeded')->sum('refunded_cents');

        if ($this->status !== InvoiceStatus::Void) {
            $this->status = match (true) {
                $this->paid_cents <= 0 => InvoiceStatus::Open,
                $this->paid_cents >= $this->total_cents => InvoiceStatus::Paid,
                default => InvoiceStatus::PartPaid,
            };
        }

        $this->save();
    }
}
