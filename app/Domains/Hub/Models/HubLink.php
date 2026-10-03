<?php

declare(strict_types=1);

namespace App\Domains\Hub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $identity_id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $status
 * @property Carbon $linked_at
 * @property Carbon|null $revoked_at
 */
class HubLink extends Model
{
    public $timestamps = false;

    protected $connection = 'hub';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
