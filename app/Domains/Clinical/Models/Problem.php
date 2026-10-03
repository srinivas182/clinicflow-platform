<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $patient_id
 * @property string $icd10_code
 * @property string $description
 * @property bool $chronic
 * @property string $status
 * @property Carbon|null $onset_date
 */
class Problem extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['chronic' => 'boolean', 'onset_date' => 'date'];
    }
}
