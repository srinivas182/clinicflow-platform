<?php

declare(strict_types=1);

namespace App\Domains\Claims\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $claim_id
 * @property string $scheme_reference
 * @property int $paid_cents
 * @property string|null $message
 * @property Carbon $received_at
 */
class Remittance extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
