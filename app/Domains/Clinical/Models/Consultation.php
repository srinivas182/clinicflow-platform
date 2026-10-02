<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Models;

use App\Domains\Patients\Models\Patient;
use App\Domains\Prescribing\Models\Prescription;
use App\Domains\Visits\Models\Visit;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Doctor's notes for one visit (SOAP). Saved with optimistic locking.
 *
 * @property string $id
 * @property string $visit_id
 * @property string $patient_id
 * @property int $doctor_staff_id
 * @property string|null $subjective
 * @property string|null $objective
 * @property string|null $assessment
 * @property string|null $plan
 * @property string $status
 * @property int $lock_version
 * @property Carbon|null $completed_at
 * @property-read Visit $visit
 * @property-read Patient $patient
 */
class Consultation extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /**
     * @return BelongsTo<Visit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return HasMany<ConsultationDiagnosis, $this>
     */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(ConsultationDiagnosis::class);
    }

    /**
     * @return HasMany<Prescription, $this>
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
