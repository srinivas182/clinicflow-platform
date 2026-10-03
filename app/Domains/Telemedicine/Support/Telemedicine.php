<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Support;

use App\Domains\Platform\Models\Subscription;

/**
 * Whether a provider can offer online consults: the package must offer the
 * Telemedicine add-on and the provider must have switched it on.
 */
final class Telemedicine
{
    public static function enabledFor(string $tenantId): bool
    {
        $subscription = Subscription::query()->with('package')->where('tenant_id', $tenantId)->latest('id')->first();
        if (! $subscription instanceof Subscription) {
            return false;
        }
        $offered = in_array('telemedicine', (array) ($subscription->package->addons ?? []), true);
        $active = in_array('telemedicine', (array) ($subscription->getAttribute('addons') ?? []), true);

        return $offered && $active;
    }

    public static function roomName(string $tenantId, string $appointmentId): string
    {
        return "cf_{$tenantId}_{$appointmentId}";
    }

    /**
     * @return array{0: string, 1: string}|null [tenant id, appointment id]
     */
    public static function parseRoom(string $room): ?array
    {
        if (preg_match('/^cf_(.+)_([0-9A-HJKMNP-TV-Z]{26})$/i', $room, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }
}
