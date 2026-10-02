<?php

declare(strict_types=1);

namespace App\Domains\Patients\Models;

use App\Domains\Patients\Enums\ConsentGivenBy;
use App\Domains\Patients\Enums\ConsentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $patient_id
 * @property ConsentType $type
 * @property ConsentGivenBy $given_by
 * @property bool $maturity_confirmed
 * @property Carbon $granted_at
 * @property Carbon|null $withdrawn_at
 */
class PatientConsent extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConsentType::class,
            'given_by' => ConsentGivenBy::class,
            'maturity_confirmed' => 'boolean',
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
