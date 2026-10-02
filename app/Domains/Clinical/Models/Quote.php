<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Procedure quote shown to the patient before the procedure.
 *
 * @property string $id
 * @property string $visit_id
 * @property string $patient_id
 * @property list<array{code: string, description: string, quantity: int, unit_cents: int}> $lines
 * @property int $total_cents
 * @property string $status
 * @property string|null $preauth_number
 * @property Carbon|null $accepted_at
 */
class Quote extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['lines' => 'array', 'accepted_at' => 'datetime', 'total_cents' => 'integer'];
    }
}
