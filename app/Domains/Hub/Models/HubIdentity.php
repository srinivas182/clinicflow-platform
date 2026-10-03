<?php

declare(strict_types=1);

namespace App\Domains\Hub\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person across the network: one cell number = one identity.
 * Identity data only — never clinical records.
 *
 * @property string $id
 * @property string $cell
 * @property string|null $sa_id_hash
 * @property string $first_names
 * @property string $surname
 * @property Carbon $date_of_birth
 */
class HubIdentity extends Model
{
    use HasUlids;

    protected $connection = 'hub';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }

    /**
     * @return HasMany<HubLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(HubLink::class, 'identity_id');
    }

    /**
     * What another provider may see before the patient approves: initials and birth year only.
     */
    public function masked(): string
    {
        return mb_substr($this->first_names, 0, 1).'*** '.mb_substr($this->surname, 0, 1).'*** · born '.$this->date_of_birth->format('Y')
            .' · cell ••• '.substr($this->cell, -3);
    }
}
