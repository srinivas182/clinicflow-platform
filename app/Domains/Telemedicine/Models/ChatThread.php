<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Models;

use App\Domains\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * kind "consult": a live, time-boxed chat consult; kind "followup": the short
 * question window after any online consult.
 *
 * @property int $id
 * @property string|null $appointment_id
 * @property string $patient_id
 * @property int $doctor_staff_id
 * @property string $kind
 * @property Carbon $opens_at
 * @property Carbon $closes_at
 * @property-read Patient $patient
 */
class ChatThread extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'closes_at' => 'datetime'];
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function isOpen(): bool
    {
        return now()->between($this->opens_at, $this->closes_at);
    }
}
