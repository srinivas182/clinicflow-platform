<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Enums\ProviderType;
use Illuminate\Database\Eloquent\Model;
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
        ];
    }

    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }
}
