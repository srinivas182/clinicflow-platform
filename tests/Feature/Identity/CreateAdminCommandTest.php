<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;

uses(DatabaseMigrations::class);

it('creates a super admin with a hidden, rule-checked password', function (): void {
    $this->artisan('clinicflow:create-admin', ['--name' => 'Srini', '--email' => 'Srini@DrBusinessFlow.com', '--phone' => '0821234567'])
        ->expectsQuestion('Password (10+ characters, letters and numbers; not shown)', 'clinic-2026-strong')
        ->expectsQuestion('Repeat the password', 'clinic-2026-strong')
        ->expectsOutputToContain('Super admin srini@drbusinessflow.com created')
        ->assertSuccessful();
    $user = User::query()->where('email', 'srini@drbusinessflow.com')->firstOrFail();
    expect((bool) $user->is_platform_admin)->toBeTrue()->and(Hash::check('clinic-2026-strong', (string) $user->password))->toBeTrue();
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
