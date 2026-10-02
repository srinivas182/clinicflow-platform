<?php

declare(strict_types=1);

namespace App\Domains\Claims\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $patient_id
 * @property string|null $visit_id
 * @property string $scheme
 * @property string $member_number
 * @property string|null $dependant_code
 * @property string $status
 * @property string|null $message
 * @property string $switch
 * @property Carbon $checked_at
 */
class EligibilityCheck extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['response' => 'array', 'checked_at' => 'datetime'];
    }
}
