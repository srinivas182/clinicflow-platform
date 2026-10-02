<?php

declare(strict_types=1);

namespace App\Domains\Visits\Models;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Patients\Models\Patient;
use App\Domains\Visits\Enums\LeftReason;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One patient visit for one day, identified by a queue ticket (A001, A002, ...).
 *
 * @property string $id
 * @property string $patient_id
 * @property string|null $appointment_id
 * @property Carbon $visit_date
 * @property string $ticket
 * @property VisitStage $stage
 * @property PayerType $payer_type
 * @property int|null $preferred_staff_id
 * @property int|null $doctor_id
 * @property string|null $triage_colour
 * @property LeftReason|null $left_reason
 * @property string|null $left_note
 * @property string $check_in_channel
 * @property Carbon|null $called_at
 * @property int|null $room_id
 * @property Carbon|null $alert_accepted_at
 * @property Carbon $stage_changed_at
 * @property Carbon $created_at
 * @property-read Patient $patient
 * @property-read Invoice|null $invoice
 */
class Visit extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'stage' => VisitStage::class,
            'payer_type' => PayerType::class,
            'left_reason' => LeftReason::class,
            'stage_changed_at' => 'datetime',
            'called_at' => 'datetime',
            'alert_accepted_at' => 'datetime',
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
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * @return HasMany<VisitStageEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(VisitStageEvent::class);
    }

    public function minutesInStage(): int
    {
        return (int) $this->stage_changed_at->diffInMinutes(now());
    }
}
