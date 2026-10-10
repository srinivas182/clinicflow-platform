<?php

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Support\Totp;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Wallet\Support\WalletSettings;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->outbox = new class implements MessageSender
    {
        /** @var list<array{0: string, 1: string}> */
        public array $sent = [];

        public function send(string $channel, string $recipient, ?string $subject, string $body): bool
        {
            $this->sent[] = [$channel, $recipient];

            return true;
        }
    };
    $this->app->instance(MessageSender::class, $this->outbox);
    $this->app->forgetInstance(OtpSender::class);
    $this->user = User::factory()->create(['email' => 'nurse@drbusinessflow.com', 'phone' => '0821234567', 'password' => 'correct-horse-battery']);
    $this->admin = User::factory()->create(['email' => 'admin@drbusinessflow.com', 'password' => 'correct-horse-battery']);
    $this->admin->forceFill(['is_platform_admin' => true])->save();
});

it('signs in with a password only when two-step sign-in is off, and forces nothing', function (): void {
    WalletSettings::put('security.two_factor_enabled', false);
    config(['clinicflow.security.require_authenticator_for_admins' => true]);
    $this->post('http://localhost/login', ['login' => 'admin@drbusinessflow.com', 'password' => 'correct-horse-battery'])->assertRedirect('/workspaces');
    $this->assertAuthenticatedAs($this->admin);
    expect($this->outbox->sent)->toBe([]);
    // No authenticator set-up is forced on the super admin while two-step sign-in is off.
    $this->get('http://localhost/workspaces')->assertOk();
    $this->artisan('security:check')->expectsOutputToContain('✗ Two-step sign-in is on');
});

it('uses the first method in the chosen order that works for the person', function (): void {
    WalletSettings::put('security.two_factor_enabled', true);
    WalletSettings::put('security.two_factor_methods', ['sms', 'email', 'authenticator']);
    $this->post('http://localhost/login', ['login' => 'nurse@drbusinessflow.com', 'password' => 'correct-horse-battery'])->assertRedirect('/login/verify');
    expect($this->outbox->sent)->toBe([['sms', '0821234567']]);

    $this->post('http://localhost/logout');
    $this->outbox->sent = [];
    $this->user->forceFill(['totp_secret' => Totp::newSecret(), 'totp_confirmed_at' => now()])->save();
    WalletSettings::put('security.two_factor_methods', ['authenticator', 'email']);
    $this->post('http://localhost/login', ['login' => 'nurse@drbusinessflow.com', 'password' => 'correct-horse-battery'])->assertRedirect('/login/verify');
    expect($this->outbox->sent)->toBe([])
        ->and(DB::table('login_challenges')->where('user_id', $this->user->id)->where('method', 'authenticator')->exists())->toBeTrue();
});

it('lets only a super admin change it, after confirming identity, and never in a way that locks them out', function (): void {
    $this->actingAs($this->user)->get('http://localhost/admin/security')->assertForbidden();
    $this->flushSession();
    $this->actingAs($this->admin)->get('http://localhost/admin/security')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/Security')->where('enabled', true));
    $this->actingAs($this->admin)->post('http://localhost/admin/security', ['enabled' => true, 'methods' => ['email']])->assertRedirect('http://localhost/confirm-identity');

    $confirmed = [RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()];
    // No email supplier and no authenticator: the admin could not sign in, so it is refused.
    $this->actingAs($this->admin)->withSession($confirmed)->post('http://localhost/admin/security', ['enabled' => true, 'methods' => ['email', 'sms']])->assertSessionHasErrors('methods');

    $this->admin->forceFill(['totp_secret' => Totp::newSecret(), 'totp_confirmed_at' => now()])->save();
    $this->actingAs($this->admin)->withSession($confirmed)->post('http://localhost/admin/security', ['enabled' => true, 'methods' => ['authenticator', 'email']])->assertSessionHasNoErrors();
    expect(WalletSettings::get('security.two_factor_methods'))->toBe(['authenticator', 'email']);
    $this->actingAs($this->admin)->withSession($confirmed)->post('http://localhost/admin/security', ['enabled' => false, 'methods' => ['authenticator']])->assertSessionHasNoErrors();
    expect(WalletSettings::get('security.two_factor_enabled'))->toBeFalse()
        ->and(DB::table('activity_log')->where('description', 'Two-step sign-in switched off')->exists())->toBeTrue();
});
