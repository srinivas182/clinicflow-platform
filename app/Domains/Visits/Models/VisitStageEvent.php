<?php

declare(strict_types=1);

namespace App\Domains\Visits\Models;

use App\Domains\Visits\Enums\VisitStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Timestamped stage move — the source for wait-time reporting.
 *
 * @property int $id
 * @property string $visit_id
 * @property VisitStage|null $from_stage
 * @property VisitStage $to_stage
 * @property int|null $by_staff_id
 * @property Carbon $occurred_at
 */
class VisitStageEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['from_stage' => VisitStage::class, 'to_stage' => VisitStage::class, 'occurred_at' => 'datetime'];
    }
}
