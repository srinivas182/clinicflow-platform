<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * "Trust this device for 30 days": the password is still required, but the sign-in code is skipped
 * on that device. The device keeps a random token in an encrypted, HTTP-only cookie; only its hash
 * is stored. Super admins always enter a code.
 */
class TrustedDevices
{
    public const COOKIE = 'cf_trusted_device';

    public const DAYS = 30;

    public function allowed(User $user): bool
    {
        return ! (bool) $user->is_platform_admin;
    }

    public function remember(User $user, string $userAgent): Cookie
    {
        $token = Str::random(64);
        DB::table('trusted_devices')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'label' => mb_substr($userAgent, 0, 120),
            'last_used_at' => now(), 'expires_at' => now()->addDays(self::DAYS), 'created_at' => now()]);
        activity('auth')->causedBy($user)->log('Device trusted for 30 days');

        return cookie(self::COOKIE, $user->id.'|'.$token, self::DAYS * 24 * 60, null, null, null, true, false, 'lax');
    }

    /** Is this browser's cookie a valid, unexpired trusted device for this user? */
    public function trusted(User $user, ?string $cookie): bool
    {
        if (! $this->allowed($user) || $cookie === null || ! str_contains($cookie, '|')) {
            return false;
        }
        [$userId, $token] = explode('|', $cookie, 2);
        if ((int) $userId !== (int) $user->id) {
            return false;
        }

        // Check first, then record the use (an update that changes nothing reports 0 rows on MySQL).
        $device = DB::table('trusted_devices')->where('user_id', $user->id)->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now());
        if (! (clone $device)->exists()) {
            return false;
        }
        $device->update(['last_used_at' => now()]);

        return true;
    }

    public function forget(User $user, ?int $id = null): void
    {
        DB::table('trusted_devices')->where('user_id', $user->id)->when($id !== null, fn ($q) => $q->where('id', $id))->delete();
        activity('auth')->causedBy($user)->log($id === null ? 'All trusted devices removed' : 'Trusted device removed');
    }
}
