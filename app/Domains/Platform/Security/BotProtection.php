<?php

declare(strict_types=1);

namespace App\Domains\Platform\Security;

use App\Domains\Wallet\Support\WalletSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bot protection with Cloudflare Turnstile (Admin → Security). One switch for the main website and
 * every practice website. The secret key is stored encrypted. If Cloudflare cannot be reached, forms
 * keep working (logged), so an outage never locks people out.
 */
final class BotProtection
{
    public const SCRIPT_HOST = 'https://challenges.cloudflare.com';

    public static function enabled(): bool
    {
        return (bool) WalletSettings::get('security.bot_protection_enabled') && self::siteKey() !== '' && self::secret() !== '';
    }

    public static function siteKey(): string
    {
        return (string) WalletSettings::get('security.turnstile_site_key');
    }

    public static function secret(): string
    {
        $value = WalletSettings::get('security.turnstile_secret');
        if (! is_string($value) || $value === '') {
            return '';
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return '';
        }
    }

    public static function saveSecret(string $secret): void
    {
        WalletSettings::put('security.turnstile_secret', Crypt::encryptString($secret));
    }

    /** True if the check passed, false if it failed, null if Cloudflare could not be reached. */
    public static function verify(string $token, ?string $ip): ?bool
    {
        try {
            $response = Http::asForm()->timeout(5)->post(self::SCRIPT_HOST.'/turnstile/v0/siteverify', ['secret' => self::secret(), 'response' => $token, 'remoteip' => $ip]);
        } catch (\Throwable $e) {
            Log::warning('Bot protection check could not reach Cloudflare; allowed', ['error' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            Log::warning('Bot protection check failed at Cloudflare; allowed', ['status' => $response->status()]);

            return null;
        }

        return (bool) $response->json('success', false);
    }
}
