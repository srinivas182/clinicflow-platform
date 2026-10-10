<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use App\Domains\Messaging\Models\MessagingProvider;
use App\Domains\Wallet\Support\WalletSettings;
use App\Models\User;

/**
 * Two-step sign-in, set by the super admin (Admin → Security). Off by default (CLINICFLOW_TWO_FACTOR);
 * when on, each person gets the first method in the chosen order that works for them: the authenticator
 * app if they set it up, email if an email supplier is configured, SMS if an SMS supplier is configured.
 */
final class TwoFactorPolicy
{
    public const METHODS = ['authenticator', 'email', 'sms'];

    public static function enabled(): bool
    {
        $value = WalletSettings::get('security.two_factor_enabled');

        return $value === null ? (bool) config('clinicflow.security.two_factor_default', false) : (bool) $value;
    }

    /**
     * @return list<string>
     */
    public static function methods(): array
    {
        $value = WalletSettings::get('security.two_factor_methods');
        $list = is_array($value) ? array_values(array_unique(array_filter($value, fn ($m) => in_array($m, self::METHODS, true)))) : [];

        return $list === [] ? self::METHODS : $list;
    }

    /** The method this person signs in with, or null if they have none of the chosen ones. */
    public static function methodFor(User $user): ?string
    {
        $fallback = null;
        foreach (self::methods() as $method) {
            if ($method === 'authenticator' && $user->totp_confirmed_at !== null) {
                return 'authenticator';
            }
            if (self::hasContact($user, $method)) {
                if (MessagingProvider::activeFor($method) !== null) {
                    return $method;
                }
                $fallback ??= $method; // no supplier yet: the code is recorded, not delivered
            }
        }

        return $fallback;
    }

    /** Email or SMS, in the chosen order, for sending a code. */
    public static function messageChannelFor(User $user): ?string
    {
        $fallback = null;
        foreach (self::methods() as $method) {
            if ($method !== 'authenticator' && self::hasContact($user, $method)) {
                if (MessagingProvider::activeFor($method) !== null) {
                    return $method;
                }
                $fallback ??= $method;
            }
        }

        return $fallback;
    }

    /**
     * Could this person actually complete two-step sign-in with these methods? (Prevents lock-out.)
     *
     * @param  list<string>  $methods
     */
    public static function usableBy(User $user, array $methods): bool
    {
        foreach ($methods as $method) {
            if ($method === 'authenticator' ? $user->totp_confirmed_at !== null : (self::hasContact($user, $method) && MessagingProvider::activeFor($method) !== null)) {
                return true;
            }
        }

        return false;
    }

    private static function hasContact(User $user, string $method): bool
    {
        return $method === 'email' ? $user->email !== '' : ($method === 'sms' && is_string($user->phone) && $user->phone !== '');
    }
}
