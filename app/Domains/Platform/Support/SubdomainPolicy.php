<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

use Illuminate\Support\Str;

/**
 * Rules for a provider's free subdomain: <slug>.clinicflow.co.za.
 */
final class SubdomainPolicy
{
    public const RESERVED = [
        'www', 'app', 'admin', 'accounts', 'api', 'mail', 'smtp', 'ftp', 'status', 'help', 'support',
        'docs', 'blog', 'partners', 'billing', 'pay', 'static', 'cdn', 'assets', 'media', 'staging',
        'test', 'dev', 'clinicflow', 'connect', 'video', 'ws', 'auth', 'login',
    ];

    public static function suggest(string $name): string
    {
        $slug = Str::slug(Str::of($name)->replaceMatches('/\b(dr|the|pty|ltd)\b\.?/i', '')->toString());

        return Str::limit($slug, 40, '');
    }

    public static function isValid(string $slug): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', $slug) === 1
            && ! str_contains($slug, '--')
            && ! in_array($slug, self::RESERVED, true);
    }

    /** A practice address under the platform domain, or under a white-label brand's own domain. */
    public static function domainFor(string $slug, ?string $base = null): string
    {
        return $slug.'.'.($base ?? config('clinicflow.provider_domain'));
    }
}
