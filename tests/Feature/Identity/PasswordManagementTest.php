<?php

use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Platform\Enums\ProviderType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->outbox = new class implements MessageSender
    {
        /** @var list<array{0: string, 1: ?string, 2: string}> */
        public array $sent = [];

        public function send(string $channel, string $recipient, ?string $subject, string $body): bool
        {
            $this->sent[] = [$recipient, $subject, $body];

            return true;
        }
    };
    $this->app->instance(MessageSender::class, $this->outbox);
    $this->user = User::factory()->create(['email' => 'nurse@drbusinessflow.com', 'password' => 'old-password-2026']);
});

it('changes your own password, signs out other devices and sends a notice', function (): void {
    DB::table('trusted_devices')->insert(['user_id' => $this->user->id, 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDays(10), 'created_at' => now()]);
    $post = fn (array $data) => $this->actingAs($this->user)->postJson('http://localhost/account/security/password', $data);
    $post(['current_password' => 'wrong-password-1', 'password' => 'new-password-2026', 'password_confirmation' => 'new-password-2026'])->assertStatus(422)->assertJsonValidationErrors('current_password');
    $post(['current_password' => 'old-password-2026', 'password' => 'short1', 'password_confirmation' => 'short1'])->assertStatus(422)->assertJsonValidationErrors('password');
    $post(['current_password' => 'old-password-2026', 'password' => 'new-password-2026', 'password_confirmation' => 'new-password-2026'])->assertOk();

    expect(Hash::check('new-password-2026', (string) $this->user->fresh()->password))->toBeTrue()
        ->and(DB::table('trusted_devices')->count())->toBe(0)
        ->and(collect($this->outbox->sent)->pluck(1)->all())->toContain('Your password was changed');
});

it('resets a forgotten password with a single-use, 60-minute emailed link without revealing who has an account', function (): void {
    $this->post('http://localhost/forgot-password', ['email' => 'nobody@drbusinessflow.com'])->assertSessionHas('success');
    expect($this->outbox->sent)->toBe([]);

    Cache::put('login-locked:'.$this->user->id, 1, 900);
    $this->post('http://localhost/forgot-password', ['email' => 'nurse@drbusinessflow.com'])->assertSessionHas('success');
    preg_match('#/reset-password/([A-Za-z0-9]+)\?email=#', (string) $this->outbox->sent[0][2], $m);
    $token = $m[1];
    $this->get("http://localhost/reset-password/{$token}?email=nurse@drbusinessflow.com")->assertOk()->assertInertia(fn ($p) => $p->component('Auth/ResetPassword'));

    $reset = fn (string $t) => $this->post('http://localhost/reset-password', ['token' => $t, 'email' => 'nurse@drbusinessflow.com', 'password' => 'brand-new-2026x', 'password_confirmation' => 'brand-new-2026x']);
    $reset($token)->assertRedirect('/login');
    expect(Hash::check('brand-new-2026x', (string) $this->user->fresh()->password))->toBeTrue()->and(Cache::has('login-locked:'.$this->user->id))->toBeFalse();
    $reset($token)->assertSessionHasErrors('email');

    $this->post('http://localhost/forgot-password', ['email' => 'nurse@drbusinessflow.com']);
    preg_match('#/reset-password/([A-Za-z0-9]+)\?email=#', (string) $this->outbox->sent[1][2], $m2);
    $this->travel(61)->minutes();
    $reset($m2[1])->assertSessionHasErrors('email');
});

it('sets a password from the server, typed hidden and rule-checked', function (): void {
    $this->artisan('clinicflow:set-password', ['email' => 'nurse@drbusinessflow.com'])
        ->expectsQuestion('New password (10+ characters, letters and numbers; not shown)', 'server-set-2026')
        ->expectsQuestion('Repeat the new password', 'server-set-2026')->assertSuccessful();
    expect(Hash::check('server-set-2026', (string) $this->user->fresh()->password))->toBeTrue();
    $this->artisan('clinicflow:set-password', ['email' => 'nurse@drbusinessflow.com'])
        ->expectsQuestion('New password (10+ characters, letters and numbers; not shown)', 'short')
        ->expectsQuestion('Repeat the new password', 'short')->assertFailed();
});

it('names the manager role for the kind of practice', function (): void {
    expect(StaffRole::Manager->labelFor(ProviderType::Pharmacy))->toBe('Pharmacy manager')
        ->and(StaffRole::Manager->labelFor(ProviderType::Lab))->toBe('Lab manager')
        ->and(StaffRole::Manager->labelFor(ProviderType::Clinic))->toBe('Clinic manager')
        ->and(StaffRole::Doctor->labelFor(ProviderType::Lab))->toBe('Doctor');
});
