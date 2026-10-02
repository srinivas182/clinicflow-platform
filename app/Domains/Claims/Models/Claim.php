<?php

declare(strict_types=1);

namespace App\Domains\Claims\Models;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string $patient_id
 * @property string $scheme
 * @property string $member_number
 * @property string|null $dependant_code
 * @property string $status
 * @property int $total_cents
 * @property int $submissions
 * @property string|null $switch
 * @property string|null $switch_reference
 * @property string|null $rejection_reason
 * @property Carbon|null $submitted_at
 * @property-read Invoice $invoice
 * @property-read Patient $patient
 */
class Claim extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['response' => 'array', 'submitted_at' => 'datetime', 'total_cents' => 'integer', 'submissions' => 'integer'];
    }

    /**
     * @return HasMany<ClaimLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ClaimLine::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
