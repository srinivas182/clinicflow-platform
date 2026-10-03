<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Models;

use App\Domains\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * direction "out" (we referred) or "in" (referred to us). Status: sent → accepted → booked → seen → feedback; or declined/cancelled.
 *
 * @property string $id
 * @property string $direction
 * @property string $patient_id
 * @property string|null $consultation_id
 * @property int|null $staff_id
 * @property string|null $other_tenant_id
 * @property string $other_name
 * @property string $specialty
 * @property string $urgency
 * @property string $reason
 * @property list<string> $shared_categories
 * @property array<string, mixed> $summary
 * @property string $status
 * @property string|null $hub_referral_id
 * @property Carbon|null $appointment_at
 * @property string|null $feedback
 * @property Carbon|null $feedback_at
 * @property-read Patient $patient
 */
class Referral extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['shared_categories' => 'array', 'summary' => 'array', 'appointment_at' => 'datetime', 'feedback_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
