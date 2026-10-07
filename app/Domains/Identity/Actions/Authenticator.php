<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Membership;
use App\Domains\Identity\Support\Totp;
use App\Domains\Platform\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Authenticator-app sign-in: set-up (confirmed with a working code), verification with
 * replay protection, one-time recovery codes, and who must use it.
 */
class Authenticator
{
    public const ISSUER = 'Clinic Flow';

    /**
     * Starts set-up: a new secret is stored but not switched on until a code from the app is confirmed.
     *
     * @return array{secret: string, uri: string}
     */
    public function start(User $user): array
    {
        if ($user->totp_confirmed_at !== null) {
            throw ValidationException::withMessages(['code' => 'The authenticator app is already set up. Turn it off first to move it to a new phone.']);
        }
        $secret = Totp::newSecret();
        $user->forceFill(['totp_secret' => $secret, 'totp_last_step' => null])->save();

        return ['secret' => $secret, 'uri' => Totp::uri($secret, (string) $user->email, self::ISSUER)];
    }

    /**
     * Confirms set-up with a code from the app and returns ten recovery codes (shown once).
     *
     * @return list<string>
     */
    public function confirm(User $user, string $code): array
    {
        $step = $user->totp_secret === null ? null : Totp::matchingStep((string) $user->totp_secret, trim($code), time());
        if ($user->totp_confirmed_at !== null || $step === null) {
            throw ValidationException::withMessages(['code' => 'That code is not correct. Check the time on your phone and try the newest code.']);
        }
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['totp_confirmed_at' => now(), 'totp_last_step' => $step, 'recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();
        activity('auth')->causedBy($user)->log('Authenticator app set up');

        return $codes;
    }

    /** Sign-in check: a current app code (never reused) or an unused recovery code. */
    public function verify(User $user, string $code): bool
    {
        $code = trim($code);
        if ($user->totp_confirmed_at === null || $user->totp_secret === null) {
            return false;
        }
        $step = Totp::matchingStep((string) $user->totp_secret, $code, time());
        if ($step !== null) {
            if ($user->totp_last_step !== null && $step <= (int) $user->totp_last_step) {
                return false; // the same code (or an older one) can't be used twice
            }
            $user->forceFill(['totp_last_step' => $step])->save();

            return true;
        }

        return $this->useRecoveryCode($user, $code);
    }

    /** Turning it off needs a current app code (or a recovery code). */
    public function disable(User $user, string $code): void
    {
        if (! $this->verify($user, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }
        $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null, 'recovery_codes' => null])->save();
        activity('auth')->causedBy($user)->log('Authenticator app turned off');
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        if (! $this->verify($user, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();
        activity('auth')->causedBy($user)->log('Recovery codes replaced');

        return $codes;
    }

    /** Super admins, and owners or practice admins of any practice, must use an authenticator app. */
    public function required(User $user): bool
    {
        if (! (bool) config('clinicflow.security.require_authenticator_for_admins', true)) {
            return false;
        }

        if ((bool) $user->is_platform_admin || Membership::query()->where('user_id', $user->id)
            ->whereIn('role', [StaffRole::Owner->value, StaffRole::PracticeAdmin->value])->usable()->exists()) {
            return true;
        }

        // A practice can require it for all of its staff.
        $practices = Membership::query()->where('user_id', $user->id)->usable()->pluck('tenant_id');

        return $practices->isNotEmpty() && Provider::query()->whereIn('id', $practices)->where('require_authenticator', true)->exists();
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        if (preg_match('/^[a-z0-9]{5}-[a-z0-9]{5}$/', strtolower($code)) !== 1) {
            return false;
        }
        $hashes = (array) ($user->recovery_codes ?? []);
        foreach ($hashes as $i => $hash) {
            if (Hash::check(strtolower($code), (string) $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['recovery_codes' => array_values($hashes)])->save();
                activity('auth')->causedBy($user)->withProperties(['remaining' => count($hashes)])->log('Signed in with a recovery code');

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function newRecoveryCodes(): array
    {
        return array_map(fn () => strtolower(Str::random(5).'-'.Str::random(5)), range(1, 10));
    }
}
