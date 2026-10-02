<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Models;

use App\Domains\Clinical\Enums\TriageColour;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $visit_id
 * @property int $bp_systolic
 * @property int $bp_diastolic
 * @property int $pulse
 * @property float $temperature
 * @property TriageColour $suggested_colour
 * @property TriageColour $colour
 * @property Carbon $created_at
 */
class TriageRecord extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['suggested_colour' => TriageColour::class, 'colour' => TriageColour::class, 'temperature' => 'float'];
    }
}
