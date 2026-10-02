<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Messages a provider used in a month (Platform database).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $period
 * @property int $units
 */
class MessageUsage extends Model
{
    use CentralConnection;

    public $timestamps = false;

    protected $table = 'message_usage';

    protected $guarded = ['id'];
}
