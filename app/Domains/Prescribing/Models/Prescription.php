<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Models;

use App\Domains\Clinical\Models\Consultation;
use App\Domains\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A prescription version. Signed versions are frozen; a change creates the
 * next version, and only the newest signed version can be dispensed.
 *
 * @property string $id
 * @property string $consultation_id
 * @property string $patient_id
 * @property int $prescriber_staff_id
 * @property int $version
 * @property string|null $previous_version_id
 * @property string $status
 * @property Carbon|null $signed_at
 * @property string|null $signature_hash
 * @property int|null $issued_document_id
 * @property string|null $change_reason
 * @property-read Consultation $consultation
 * @property-read Patient $patient
 */
class Prescription extends Model
{
    use HasUlids;

    public const DRAFT = 'draft';

    public const SIGNED = 'signed';

    public const SUPERSEDED = 'superseded';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['signed_at' => 'datetime', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (Prescription $p): void {
            if ($p->getOriginal('status') === self::SIGNED) {
                $allowed = ['status', 'updated_at', 'issued_document_id'];
                if (array_diff(array_keys($p->getDirty()), $allowed) !== [] || ! in_array($p->status, [self::SIGNED, self::SUPERSEDED], true)) {
                    throw new LogicException('A signed prescription cannot be changed. Create a new version.');
                }
            }
            if ($p->getOriginal('status') === self::SUPERSEDED) {
                throw new LogicException('A superseded prescription cannot be changed.');
            }
        });
        static::deleting(function (Prescription $p): void {
            if ($p->status !== self::DRAFT) {
                throw new LogicException('Only draft prescriptions can be discarded.');
            }
        });
    }

    /**
     * @return HasMany<PrescriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    /**
     * @return BelongsTo<Consultation, $this>
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Only the newest signed version of a script may be dispensed.
     */
    public function isDispensable(): bool
    {
        return $this->status === self::SIGNED
            && ! static::query()->where('consultation_id', $this->consultation_id)->where('version', '>', $this->version)->where('status', self::SIGNED)->exists();
    }
}
