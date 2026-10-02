<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\GatewayMode;
use App\Domains\Platform\Models\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A provider's saved card for subscription auto-debit (Platform database).
 * Only the gateway's token is kept — encrypted — never card numbers.
 *
 * @property int $id
 * @property string $tenant_id
 * @property Gateway $gateway
 * @property GatewayMode $mode
 * @property string $token
 * @property string $token_hash
 * @property string|null $email
 * @property string|null $card_brand
 * @property string|null $card_last4
 * @property string|null $card_expiry
 * @property string $status
 * @property int|null $consented_by
 * @property Carbon $consented_at
 * @property int $failure_count
 * @property Carbon|null $last_charged_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoke_note
 * @property-read Provider $provider
 */
class BillingMandate extends Model
{
    use CentralConnection;

    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    protected $guarded = ['id'];

    protected $hidden = ['token', 'token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway' => Gateway::class,
            'mode' => GatewayMode::class,
            'token' => 'encrypted',
            'consented_at' => 'datetime',
            'last_charged_at' => 'datetime',
            'revoked_at' => 'datetime',
            'failure_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'tenant_id');
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public static function activeFor(string $tenantId): ?self
    {
        return static::query()->where('tenant_id', $tenantId)->where('status', self::ACTIVE)->latest('id')->first();
    }

    public function label(): string
    {
        return trim(($this->card_brand ?? 'Card').' ending '.($this->card_last4 ?? '••••'));
    }
}
