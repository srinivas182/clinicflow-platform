<?php

declare(strict_types=1);

namespace App\Domains\Hub\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Status: sent → accepted → dispensed; or rejected / cancelled (superseded by a new version).
 *
 * @property string $id
 * @property string $identity_id
 * @property string $issuer_tenant_id
 * @property string $prescription_id
 * @property string $consultation_id
 * @property int $version
 * @property string $pharmacy_tenant_id
 * @property array<string, mixed> $payload
 * @property string $signature_hash
 * @property string $status
 * @property string|null $status_note
 * @property Carbon $sent_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $dispensed_at
 */
class HubEscript extends Model
{
    use HasUlids;

    protected $connection = 'hub';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'datetime', 'accepted_at' => 'datetime', 'dispensed_at' => 'datetime', 'version' => 'integer'];
    }
}
