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
 * keep working (logged), so an outage never locks people out. Keys are checked with Cloudflare before
 * the switch can be turned on; a secret rejected later lets forms through and alerts the admin. Way
 * back in if the widget itself fails (e.g. a wrong site key): php artisan security:bot-protection-off.
 */
final class BotProtection
{
    public const SCRIPT_HOST = 'https://challenges.cloudflare.com';

    /** Set when Cloudflare rejected the saved secret key at sign-in (forms were let through). */
    public const REJECTED_KEY = 'security.turnstile_secret_rejected_at';

    public static function enabled(): bool
    {
        return (bool) WalletSettings::get('security.bot_protection_enabled') && self::siteKey() !== '' && self::secret() !== '';
    }

    /** Cloudflare site keys look like 0x4AAAAAAA… (test keys start with 1x, 2x or 3x). */
    public static function siteKeyLooksValid(string $siteKey): bool
    {
        return preg_match('/^[0-3]x[A-Za-z0-9_-]{16,}$/', $siteKey) === 1;
    }

    /**
     * Asks Cloudflare whether a secret key is valid, by verifying a dummy token: a valid key gets
     * "invalid-input-response", a wrong key "invalid-input-secret". Null when the key is accepted.
     */
    public static function checkSecret(string $secret): ?string
    {
        try {
            $response = Http::asForm()->timeout(8)->post(self::SCRIPT_HOST.'/turnstile/v0/siteverify', ['secret' => $secret, 'response' => 'key-check']);
        } catch (\Throwable) {
            return 'Could not reach Cloudflare to check the keys. Try again in a minute.';
        }
        $codes = (array) $response->json('error-codes', []);
        if (in_array('invalid-input-secret', $codes, true) || in_array('missing-input-secret', $codes, true)) {
            return 'Cloudflare rejected the secret key. Copy it again from Cloudflare → Turnstile → your site → Settings.';
        }
        if (! $response->successful() && $codes === []) {
            return 'Could not check the keys with Cloudflare (HTTP '.$response->status().'). Try again in a minute.';
        }

        return null;
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

        if (in_array('invalid-input-secret', (array) $response->json('error-codes', []), true)) {
            // A wrong secret would refuse everyone, the admin included: let the form through and alert.
            Log::critical('Bot protection: Cloudflare rejected the secret key; forms are let through until it is fixed (Admin → Security)');
            WalletSettings::put(self::REJECTED_KEY, now()->toIso8601String());

            return null;
        }

        return (bool) $response->json('success', false);
    }
}
