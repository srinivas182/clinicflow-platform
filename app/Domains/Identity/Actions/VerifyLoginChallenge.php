<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Models\LoginChallenge;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Step 2 of sign-in: check the one-time code. Five wrong attempts close the challenge.
 */
class VerifyLoginChallenge
{
    public function handle(string $challengeId, string $code): User
    {
        $challenge = LoginChallenge::query()->find($challengeId);

        if (! $challenge instanceof LoginChallenge || ! $challenge->isOpen()) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Please sign in again.']);
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');

            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }

        $challenge->forceFill(['consumed_at' => now()])->save();

        $user = $challenge->user;
        $user->forceFill(['last_login_at' => now()])->save();

        activity('auth')->causedBy($user)->log('Signed in');

        return $user;
    }
}
