<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Enums\VerificationStatus;
use App\Domains\Platform\Enums\VerificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property string $tenant_id
 * @property VerificationType $type
 * @property string|null $reference
 * @property VerificationStatus $status
 * @property string|null $notes
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property-read Provider $provider
 */
class VerificationCheck extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VerificationType::class,
            'status' => VerificationStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'tenant_id');
    }
}
