<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Models;

use App\Domains\Identity\Models\Staff;
use App\Domains\Patients\Models\Patient;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Enums\ConsultType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $patient_id
 * @property int $staff_id
 * @property int|null $roster_session_id
 * @property ConsultType $consult_type
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property AppointmentStatus $status
 * @property string|null $reason
 * @property string|null $cancelled_reason
 * @property-read Patient $patient
 * @property-read Staff $staff
 * @property-read RosterSession|null $session
 */
class Appointment extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consult_type' => ConsultType::class,
            'status' => AppointmentStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * @return BelongsTo<RosterSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(RosterSession::class, 'roster_session_id');
    }
}
