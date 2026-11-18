<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Enums\ProviderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ProviderType $provider_type
 * @property string|null $summary
 * @property int $price_monthly_cents
 * @property int $price_annual_cents
 * @property int $trial_days
 * @property int|null $pending_price_monthly_cents
 * @property Carbon|null $pending_price_from
 * @property array<string, int> $limits
 * @property list<string> $features
 * @property list<string> $addons
 * @property bool $is_active
 * @property int $sort_order
 */
class Package extends Model
{
    use CentralConnection;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_type' => ProviderType::class,
            'limits' => 'array',
            'features' => 'array',
            'addons' => 'array',
            'is_active' => 'boolean',
            'pending_price_from' => 'date',
        ];
    }

    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    public const NOTICE_DAYS = 30;

    /** Monthly price for a period starting on $date: a notified price rise applies from its date. */
    public function monthlyCentsOn(\DateTimeInterface $date): int
    {
        return $this->pending_price_monthly_cents !== null && $this->pending_price_from !== null && $this->pending_price_from->lte($date)
            ? $this->pending_price_monthly_cents : $this->price_monthly_cents;
    }

    /** Annual price (15% off twelve months) for a period starting on $date. */
    public function annualCentsOn(\DateTimeInterface $date): int
    {
        return $this->monthlyCentsOn($date) === $this->price_monthly_cents ? $this->price_annual_cents : (int) round($this->monthlyCentsOn($date) * 12 * 0.85);
    }

    /** A free package has no price: no trial, no invoice, never read-only for non-payment. */
    public function isFree(): bool
    {
        return $this->price_monthly_cents === 0 && $this->price_annual_cents === 0;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }
}
