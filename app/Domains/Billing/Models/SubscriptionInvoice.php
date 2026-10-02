<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Platform invoice to a provider for its subscription (Platform database).
 *
 * @property int $id
 * @property string $number
 * @property string $tenant_id
 * @property int $subscription_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $amount_cents
 * @property int $messaging_units
 * @property int $messaging_overage_cents
 * @property int $vat_cents
 * @property int $total_cents
 * @property string $status
 * @property string $checkout_token
 * @property bool $save_card
 * @property int|null $save_card_consented_by
 * @property string|null $save_card_consent_ip
 * @property string|null $gateway
 * @property string|null $gateway_reference
 * @property Carbon $due_at
 * @property Carbon|null $paid_at
 * @property-read Subscription $subscription
 * @property-read Provider $provider
 */
class SubscriptionInvoice extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'due_at' => 'datetime', 'paid_at' => 'datetime', 'save_card' => 'boolean'];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'tenant_id');
    }
}
