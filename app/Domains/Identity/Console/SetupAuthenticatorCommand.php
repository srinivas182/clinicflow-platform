<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console;

use App\Domains\Identity\Console\Concerns\SetsUpAuthenticator;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Sets up the authenticator app for an existing account from the command line.
 */
class SetupAuthenticatorCommand extends Command
{
    use SetsUpAuthenticator;

    protected $signature = 'clinicflow:setup-authenticator {email}';

    protected $description = 'Set up the authenticator app for an existing account';

    public function handle(): int
    {
        $user = User::query()->where('email', strtolower(trim((string) $this->argument('email'))))->first();
        if (! $user instanceof User) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }
        if ($user->totp_confirmed_at !== null) {
            $this->info('The authenticator app is already set up for this account.');

            return self::SUCCESS;
        }

        return $this->setUpAuthenticator($user) ? self::SUCCESS : self::FAILURE;
    }
}
