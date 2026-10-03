<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Models;

use App\Domains\Scheduling\Models\Appointment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $appointment_id
 * @property string $room_name
 * @property string $status
 * @property string $wallet_reference
 * @property Carbon|null $doctor_joined_at
 * @property Carbon|null $patient_joined_at
 * @property Carbon|null $connected_at
 * @property Carbon|null $ended_at
 * @property int $connected_seconds
 * @property int $charged_minutes
 * @property-read Appointment $appointment
 */
class TeleSession extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['doctor_joined_at' => 'datetime', 'patient_joined_at' => 'datetime', 'connected_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
