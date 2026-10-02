<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Support;

use App\Domains\Messaging\Models\MessageUsage;
use App\Domains\Platform\Models\Package;

/**
 * Email and SMS share one allowance per package ("messages" limit). Each email
 * and each 160-character SMS segment is one message. Usage above the
 * allowance is charged on the next subscription invoice.
 */
final class MessagingUsage
{
    public static function units(string $channel, string $body): int
    {
        return $channel === 'sms' ? max(1, (int) ceil(mb_strlen($body) / 160)) : 1;
    }

    public static function record(string $tenantId, int $units): void
    {
        $row = MessageUsage::query()->firstOrCreate(['tenant_id' => $tenantId, 'period' => now()->format('Y-m')], ['units' => 0]);
        $row->increment('units', $units);
    }

    /**
     * @return array{units: int, overage_units: int, overage_cents: int}
     */
    public static function overage(string $tenantId, string $period, Package $package): array
    {
        $units = (int) MessageUsage::query()->where('tenant_id', $tenantId)->where('period', $period)->value('units');
        $allowance = $package->limit('messages') ?? 0;
        $over = max(0, $units - $allowance);

        return ['units' => $units, 'overage_units' => $over, 'overage_cents' => $over * (int) config('clinicflow.messaging.unit_price_cents', 35)];
    }
}
