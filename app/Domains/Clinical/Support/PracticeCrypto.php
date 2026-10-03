<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Support;

use Illuminate\Encryption\Encrypter;

/**
 * Encrypts clinical messages with a key unique to each practice, derived from
 * the platform key. Database access alone never reveals message content.
 * Key rotation is added in the hardening sprint.
 */
final class PracticeCrypto
{
    public static function encrypter(?string $tenantId = null): Encrypter
    {
        $tenantId ??= (string) tenant()?->getTenantKey();
        $appKey = (string) config('app.key');
        $raw = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7)) : $appKey;

        return new Encrypter(hash_hmac('sha256', 'clinicflow-practice:'.$tenantId, $raw, true), 'AES-256-CBC');
    }

    public static function encrypt(string $plain, ?string $tenantId = null): string
    {
        return self::encrypter($tenantId)->encryptString($plain);
    }

    public static function decrypt(string $cipher, ?string $tenantId = null): string
    {
        return self::encrypter($tenantId)->decryptString($cipher);
    }
}
