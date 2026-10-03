<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Delivery;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Couriers the super admin makes available. api_ready stays false until the
 * courier's API is connected with sandbox credentials; until then practices
 * book in the courier's own portal and enter the tracking number here.
 *
 * @property int $id
 * @property string $driver
 * @property bool $enabled
 * @property bool $api_ready
 */
class CourierPartner extends Model
{
    use CentralConnection;

    public const LABELS = ['pargo' => 'Pargo', 'tcg' => 'The Courier Guy', 'skynet' => 'Skynet'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'api_ready' => 'boolean'];
    }
}
