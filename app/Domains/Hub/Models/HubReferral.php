<?php

declare(strict_types=1);

namespace App\Domains\Hub\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Routing record for a referral between practices (no clinical content).
 *
 * @property string $id
 * @property string $identity_id
 * @property string $from_tenant_id
 * @property string $from_referral_id
 * @property string $to_tenant_id
 * @property string|null $to_referral_id
 * @property string $status
 */
class HubReferral extends Model
{
    use HasUlids;

    protected $connection = 'hub';

    protected $guarded = [];
}
