<?php

declare(strict_types=1);

namespace App\Domains\Claims\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $claim_id
 * @property string|null $tariff_code
 * @property string|null $nappi_code
 * @property list<string> $icd10_codes
 * @property string $description
 * @property int $quantity
 * @property int $amount_cents
 */
class ClaimLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['icd10_codes' => 'array'];
    }
}
