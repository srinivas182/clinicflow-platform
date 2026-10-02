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
     */
    public function canWrite(): bool
    {
        return in_array($this, [self::Trial, self::Active], true);
    }
}
