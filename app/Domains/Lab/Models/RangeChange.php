<?php

declare(strict_types=1);

namespace App\Domains\Lab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $lab_catalog_test_id
 * @property list<array<string, mixed>> $ranges
 * @property int $proposed_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 */
class RangeChange extends Model
{
    protected $table = 'lab_range_changes';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['ranges' => 'array', 'approved_at' => 'datetime'];
    }
}
