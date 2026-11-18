<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

use App\Domains\Identity\Models\Membership;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who to email about a practice's account (its owners) and about the platform (super admins),
 * and the practice's own web address for links in those emails.
 */
final class PracticeContacts
{
    /** @return Collection<int, User> */
    public static function owners(string $tenantId): Collection
    {
        return User::query()->whereIn('id', Membership::query()->where('tenant_id', $tenantId)->where('role', 'owner')->pluck('user_id'))->get();
    }

    /** @return Collection<int, User> */
    public static function platformAdmins(): Collection
    {
        return User::query()->where('is_platform_admin', true)->get();
    }

    /** https://<practice address><path> */
    public static function url(Provider $provider, string $path): string
    {
        $domain = (string) $provider->domains()->value('domain');
        $scheme = str_starts_with((string) config('app.url'), 'https://') ? 'https' : 'http';

        return "{$scheme}://{$domain}{$path}";
    }
}
