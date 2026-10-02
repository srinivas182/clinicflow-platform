<?php

declare(strict_types=1);

namespace App\Domains\Patients\Models;

use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Enums\Sex;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A patient registered with one provider (provider database).
 *
 * @property string $id
 * @property string $first_names
 * @property string $surname
 * @property IdType $id_type
 * @property string|null $id_number
 * @property string|null $id_number_hash
 * @property Carbon $date_of_birth
 * @property Sex|null $sex
 * @property string|null $cell
 * @property bool $no_cell
 * @property Channel $preferred_channel
 * @property string|null $guardian_name
 * @property string|null $medical_aid_scheme
 * @property string|null $medical_aid_plan
 * @property string|null $medical_aid_number
 * @property string|null $medical_aid_dependant_code
 */
class Patient extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id_number', 'id_number_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id_type' => IdType::class,
            'id_number' => 'encrypted',
            'date_of_birth' => 'date',
            'sex' => Sex::class,
            'no_cell' => 'boolean',
            'preferred_channel' => Channel::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Patient records are never deleted.'));
    }

    /**
     * @return HasMany<PatientConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(PatientConsent::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_names} {$this->surname}");
    }

    public function ageInYears(): int
    {
        return (int) $this->date_of_birth->diffInYears(now());
    }

    /**
     * Masked ID for lists: only the last four digits.
     */
    public function maskedIdNumber(): ?string
    {
        return $this->id_number === null ? null : '••••••••• '.substr($this->id_number, -4);
    }
}
