<?php

declare(strict_types=1);

namespace App\Domains\Hub\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Consent register: what the patient allowed which provider to do across the network.
 *
 * @property int $id
 * @property string $identity_id
 * @property string $tenant_id
 * @property string $scope
 * @property string $captured_via
 * @property Carbon $granted_at
 * @property Carbon|null $withdrawn_at
 */
class HubConsent extends Model
{
    public const LINK = 'link_identity';

    public const SHARE_HISTORY = 'share_history';

    public $timestamps = false;

    protected $connection = 'hub';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }
}
