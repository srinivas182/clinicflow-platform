<?php

use App\Domains\Identity\Actions\Authenticator;
use App\Domains\Identity\Support\Totp;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;

uses(DatabaseMigrations::class);

/** Authenticator with a known secret, so the test can produce the app's current code. */
function knownSecretAuthenticator(): void
{
    app()->instance(Authenticator::class, new class extends Authenticator
    {
        public function start(User $user): array
        {
            $user->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_last_step' => null])->save();

            return ['secret' => 'JBSWY3DPEHPK3PXP', 'uri' => 'otpauth://totp/test'];
        }
    });
}

it('creates a super admin and sets up the authenticator app in the terminal', function (): void {
    knownSecretAuthenticator();
    $this->artisan('clinicflow:create-admin', ['--name' => 'Srini', '--email' => 'Srini@DrBusinessFlow.com', '--phone' => '0821234567'])
        ->expectsQuestion('Password (10+ characters, letters and numbers; not shown)', 'clinic-2026-strong')
        ->expectsQuestion('Repeat the password', 'clinic-2026-strong')
        ->expectsQuestion('Enter the 6-digit code the app shows now', '000000')
        ->expectsQuestion('Enter the 6-digit code the app shows now', Totp::code('JBSWY3DPEHPK3PXP', time()))
        ->expectsOutputToContain('That code is not correct')
        ->expectsOutputToContain('Authenticator app set up.')
        ->assertSuccessful();
    $user = User::query()->where('email', 'srini@drbusinessflow.com')->firstOrFail();
    expect((bool) $user->is_platform_admin)->toBeTrue()->and(Hash::check('clinic-2026-strong', (string) $user->password))->toBeTrue()
        ->and($user->totp_confirmed_at)->not->toBeNull()->and($user->recovery_codes)->toHaveCount(10);
});

it('refuses weak or mismatched passwords and duplicate emails', function (): void {
    $run = fn (string $email, string $a, string $b) => $this->artisan('clinicflow:create-admin', ['--name' => 'X', '--email' => $email, '--phone' => '0821234567'])
        ->expectsQuestion('Password (10+ characters, letters and numbers; not shown)', $a)->expectsQuestion('Repeat the password', $b);
    $run('a@drbusinessflow.com', 'short1', 'short1')->assertFailed();
    $run('a@drbusinessflow.com', 'clinic-2026-strong', 'clinic-2026-other')->assertFailed();
    User::factory()->create(['email' => 'taken@drbusinessflow.com']);
    $run('taken@drbusinessflow.com', 'clinic-2026-strong', 'clinic-2026-strong')->assertFailed();
    expect(User::query()->where('is_platform_admin', true)->count())->toBe(0);
});

it('sets up the authenticator for an existing account', function (): void {
    knownSecretAuthenticator();
    User::factory()->create(['email' => 'owner@drbusinessflow.com']);
    $this->artisan('clinicflow:setup-authenticator', ['email' => 'owner@drbusinessflow.com'])
        ->expectsQuestion('Enter the 6-digit code the app shows now', Totp::code('JBSWY3DPEHPK3PXP', time()))
        ->expectsOutputToContain('Authenticator app set up.')->assertSuccessful();
    expect(User::query()->where('email', 'owner@drbusinessflow.com')->value('totp_confirmed_at'))->not->toBeNull();
});
