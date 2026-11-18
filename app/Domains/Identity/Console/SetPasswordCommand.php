<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console;

use App\Domains\Identity\Actions\TrustedDevices;
use App\Domains\Identity\Support\PasswordRules;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;

/**
 * Sets a person's password from the server (e.g. before email is set up). Typed hidden; same rules as sign-up.
 */
class SetPasswordCommand extends Command
{
    protected $signature = 'clinicflow:set-password {email}';

    protected $description = 'Set the password for an account (typed hidden)';

    public function handle(): int
    {
        $user = User::query()->where('email', strtolower(trim((string) $this->argument('email'))))->first();
        if (! $user instanceof User) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }
        $secret = password('New password (10+ characters, letters and numbers; not shown)', required: true);
        $confirm = password('Repeat the new password', required: true);
        $v = Validator::make(['password' => $secret, 'password_confirmation' => $confirm], ['password' => ['required', 'confirmed', PasswordRules::make()]]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user->forceFill(['password' => $secret])->save();
        app(TrustedDevices::class)->forget($user);
        activity('auth')->causedBy($user)->log('Password set from the command line');
        $this->info("Password changed for {$user->email}. Their other sessions end at their next click.");

        return self::SUCCESS;
    }
}
