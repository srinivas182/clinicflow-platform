<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console\Concerns;

use App\Domains\Identity\Actions\Authenticator;
use App\Models\User;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\text;

/**
 * Sets up the authenticator app from the command line, so the first sign-in needs no SMS or email.
 */
trait SetsUpAuthenticator
{
    protected function setUpAuthenticator(User $user): bool
    {
        $setup = app(Authenticator::class)->start($user);
        $this->newLine();
        $this->line('Set up your authenticator app (Google or Microsoft Authenticator, Authy, 1Password…):');
        $this->line('  1. In the app, choose "Enter a setup key" (or "Add account → manual").');
        $this->line('  2. Account: '.$user->email);
        $this->line('  3. Key:     '.chunk_split($setup['secret'], 4, ' '));
        $this->line('     (time-based, 6 digits)');
        $this->newLine();
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $code = text('Enter the 6-digit code the app shows now', required: true);
            try {
                $recovery = app(Authenticator::class)->confirm($user->refresh(), $code);
            } catch (ValidationException) {
                $this->error('That code is not correct. Check the time on your phone and try the newest code.');

                continue;
            }
            $this->info('Authenticator app set up.');
            $this->warn('Recovery codes — each works once if you lose your phone. Store them in your password manager now; they are not shown again:');
            foreach (array_chunk($recovery, 5) as $row) {
                $this->line('  '.implode('   ', $row));
            }

            return true;
        }
        $this->error('Authenticator not set up. Run: php artisan clinicflow:setup-authenticator '.$user->email);

        return false;
    }
}
