<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Models\LoginChallenge;
use App\Models\User;
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
        $user = User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        if (! $user instanceof User || ! Hash::check($password, $user->password)) {
            activity('auth')->withProperties(['login' => $login, 'ip' => $ip])->log('Sign-in failed');

            throw ValidationException::withMessages(['login' => 'These details do not match our records.']);
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
