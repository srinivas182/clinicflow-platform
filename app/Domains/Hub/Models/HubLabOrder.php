<?php

declare(strict_types=1);

namespace App\Domains\Hub\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Status: sent → accepted → collected → resulted; or rejected.
 *
 * @property string $id
 * @property string $identity_id
 * @property string $issuer_tenant_id
 * @property string $issuer_order_id
 * @property string $lab_tenant_id
 * @property string|null $lab_order_id
 * @property array<string, mixed> $payload
 * @property string $status
 * @property string|null $status_note
 * @property string|null $result_payload
 * @property Carbon|null $delivered_at
 */
class HubLabOrder extends Model
{
    use HasUlids;

    protected $connection = 'hub';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime'];
    }
}
