<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Delivery;

use App\Domains\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Status: requested → booked → collected → in_transit → delivered; or failed.
 *
 * @property int $id
 * @property string $patient_id
 * @property string|null $visit_id
 * @property string $driver
 * @property string $address
 * @property string $status
 * @property string|null $tracking_number
 * @property int $order_value_cents
 * @property int $fee_cents
 * @property string $payer patient|practice
 * @property string|null $proof_code_hash
 * @property Carbon|null $delivered_at
 * @property string|null $failure_reason
 * @property-read Patient $patient
 */
class Delivery extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['proof_code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
