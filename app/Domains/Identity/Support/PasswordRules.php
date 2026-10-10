<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use Illuminate\Validation\Rules\Password;

/** One place for password rules: 10+ characters, letters and numbers, not known from breaches. */
final class PasswordRules
{
    public static function make(): Password
    {
        $rule = Password::min(10)->letters()->numbers();

        return config('clinicflow.security.check_leaked_passwords', true) ? $rule->uncompromised() : $rule;
    }
}
