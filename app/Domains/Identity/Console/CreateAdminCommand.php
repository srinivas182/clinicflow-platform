<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates a platform super admin (first install, or a new administrator). The password is typed hidden
 * and must meet the same rules as sign-up. Super admins must set up an authenticator app at first sign-in.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'clinicflow:create-admin {--name=} {--email=} {--phone=}';

    protected $description = 'Create a platform super admin';

    public function handle(): int
    {
        $name = $this->opt('name') ?? text('Full name', required: true);
        $email = strtolower(trim($this->opt('email') ?? text('Email address', required: true)));
        $phone = $this->opt('phone') ?? text('Mobile number (for sign-in codes)', required: true);
        $secret = password('Password (10+ characters, letters and numbers; not shown)', required: true);
        $confirm = password('Repeat the password', required: true);

        $rule = config('clinicflow.security.check_leaked_passwords', true)
            ? Password::min(10)->letters()->numbers()->uncompromised()
            : Password::min(10)->letters()->numbers();
        $v = Validator::make(['name' => $name, 'email' => $email, 'phone' => $phone, 'password' => $secret, 'password_confirmation' => $confirm], [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', $rule],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User;
        $user->forceFill(['name' => $name, 'email' => $email, 'phone' => $phone, 'password' => $secret, 'is_platform_admin' => true])->save();
        activity('auth')->causedBy($user)->log('Super admin created from the command line');
        $this->info("Super admin {$email} created. Sign in at ".rtrim((string) config('app.url'), '/').'/login — you will be asked to set up an authenticator app.');

        return self::SUCCESS;
    }

    private function opt(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
