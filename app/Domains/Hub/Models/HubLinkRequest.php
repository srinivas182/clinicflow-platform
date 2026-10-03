<?php

declare(strict_types=1);

namespace App\Domains\Hub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $identity_id
 * @property string $tenant_id
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $approved_at
 */
class HubLinkRequest extends Model
{
    public const MAX_ATTEMPTS = 5;

    protected $connection = 'hub';

    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'approved_at' => 'datetime', 'attempts' => 'integer'];
    }
}
