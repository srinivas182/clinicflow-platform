<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

/**
 * Lifecycle of a provider account on the platform.
 */
enum ProviderStatus: string
{
    case PendingVerification = 'pending_verification';
    case Trial = 'trial';
    case Active = 'active';
    case ReadOnly = 'read_only';
    case Suspended = 'suspended';

    /**
     * Non-payment never deletes data; the provider drops to read-only.
     * Providers awaiting verification can already set up staff, rooms and rosters.
     */
    public function canWrite(): bool
    {
        return in_array($this, [self::PendingVerification, self::Trial, self::Active], true);
    }

    /**
     * Listed in the directory and able to take patient bookings.
     */
    public function isLive(): bool
    {
        return in_array($this, [self::Trial, self::Active], true);
    }
}
