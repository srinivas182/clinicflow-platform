<?php

declare(strict_types=1);

namespace App\Domains\Lab\Models;

use App\Domains\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Status: ordered → collected → resulted → verified → (reviewed) → released.
 *
 * @property string $id
 * @property string $visit_id
 * @property string $patient_id
 * @property int $ordering_staff_id
 * @property string $status
 * @property string|null $sample_barcode
 * @property Carbon|null $collected_at
 * @property int|null $resulted_by
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property bool $has_critical
 * @property Carbon|null $critical_acknowledged_at
 * @property Carbon|null $reviewed_at
 * @property string|null $doctor_comment
 * @property Carbon|null $released_at
 * @property-read Patient $patient
 */
class LabOrder extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime', 'verified_at' => 'datetime', 'critical_acknowledged_at' => 'datetime',
            'reviewed_at' => 'datetime', 'released_at' => 'datetime', 'has_critical' => 'boolean',
            'home_collection' => 'array', 'patient_requested_at' => 'datetime', 'escalated_at' => 'datetime', 'auto_released_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<LabResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(LabResult::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
