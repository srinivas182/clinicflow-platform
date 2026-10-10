<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Models\LoginChallenge;
use App\Domains\Identity\Support\TwoFactorPolicy;
use App\Domains\Messaging\Actions\SendMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Step 1 of sign-in: check email or cell number + password, then send a one-time code.
 */
class StartLogin
{
    public function __construct(private readonly OtpSender $sender) {}

    public function handle(string $login, string $password, ?string $ip = null): LoginChallenge
    {
        return $this->challenge($this->checkPassword($login, $password, $ip), $ip);
    }

    /** Step one: the password (always required, even on a trusted device). */
    public function checkPassword(string $login, string $password, ?string $ip = null): User
    {
        $user = User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        // Per-account lockout: 10 wrong passwords in 30 minutes (from any address) locks the account for 15 minutes.
        if ($user instanceof User && Cache::has('login-locked:'.$user->id)) {
            activity('auth')->causedBy($user)->withProperties(['ip' => $ip])->log('Sign-in refused: account locked');

            throw ValidationException::withMessages(['login' => 'Too many failed sign-ins. This account is locked for 15 minutes.']);
        }
        if (! $user instanceof User || ! Hash::check($password, $user->password)) {
            activity('auth')->withProperties(['login' => $login, 'ip' => $ip])->log('Sign-in failed');
            if ($user instanceof User) {
                $this->countFailure($user);
            }

            throw ValidationException::withMessages(['login' => 'These details do not match our records.']);
        }
        Cache::forget('login-failures:'.$user->id);

        return $user;
    }

    private function countFailure(User $user): void
    {
        $key = 'login-failures:'.$user->id;
        Cache::add($key, 0, now()->addMinutes(30));
        $failures = (int) Cache::increment($key);
        if ($failures >= 10 && Cache::add('login-locked:'.$user->id, 1, now()->addMinutes(15))) {
            Cache::forget($key);
            activity('auth')->causedBy($user)->log('Account locked for 15 minutes after repeated failed sign-ins');
            app(SendMessage::class)->handle('email', (string) $user->email,
                "There were 10 failed attempts to sign in to your account, so it is locked for 15 minutes.\n\nIf this wasn't you, change your password once it unlocks and use \"Sign out other devices\".",
                'Your account was locked after failed sign-ins');
        }
    }

    /** Step two: the sign-in code (by message, or from the authenticator app). */
    public function challenge(User $user, ?string $ip = null): LoginChallenge
    {
        // Authenticator app set up: no SMS or email code is sent; the app's code (or a recovery code) is asked for.
        $method = TwoFactorPolicy::methodFor($user);
        if ($method === 'authenticator' || ($method === null && $user->totp_confirmed_at !== null)) {
            return LoginChallenge::create(['user_id' => $user->id, 'code_hash' => '-', 'method' => 'authenticator', 'expires_at' => now()->addMinutes(5), 'ip' => $ip]);
        }

        $code = (string) random_int(100000, 999999);

        $challenge = LoginChallenge::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(5),
            'ip' => $ip,
        ]);

        $this->sender->send($user, $code);

        return $challenge;
    }
}
