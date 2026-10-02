<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Models;

use App\Domains\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Medicine the pharmacy could not supply in full. Not billed until supplied.
 *
 * @property int $id
 * @property string $prescription_id
 * @property int $prescription_item_id
 * @property string $patient_id
 * @property string $visit_id
 * @property int $quantity
 * @property string $status
 * @property-read Patient $patient
 */
class OwingItem extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
