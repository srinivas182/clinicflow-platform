<?php

declare(strict_types=1);

namespace App\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $staff_id
 * @property Carbon $day
 * @property array<string, int> $expected
 * @property int $expected_cash_cents
 * @property int $counted_cash_cents
 * @property int $difference_cents
 * @property string|null $reason
 * @property Carbon $closed_at
 */
class CashUp extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day' => 'date', 'expected' => 'array', 'closed_at' => 'datetime'];
    }
}
