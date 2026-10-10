<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Actions\TrustedDevices;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->otp = new class implements OtpSender
    {
        /** @var array<int, string> */
        public array $sent = [];

        public function send(User $user, string $code): void
        {
            $this->sent[$user->id] = $code;
        }
    };
    $this->app->instance(OtpSender::class, $this->otp);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->user = User::factory()->create(['email' => 'nurse@sunrise.test', 'password' => 'correct-horse-battery']);
    app(AddStaffMember::class)->handle($this->clinic, $this->user, StaffRole::Nurse);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

/** Signs in with password + code, ticking "trust this device"; returns the device cookie value. */
function signInTrusting(object $t, string $email = 'nurse@sunrise.test'): ?string
{
    $t->post('http://localhost/login', ['login' => $email, 'password' => 'correct-horse-battery']);
    $user = User::query()->where('email', $email)->firstOrFail();
    // Super admins with no practice land in the admin area; staff on the workspace chooser.
    $response = $t->post('http://localhost/login/verify', ['code' => $t->otp->sent[$user->id], 'trust_device' => '1'])
        ->assertRedirect($user->is_platform_admin ? '/admin/providers' : '/workspaces');
    $t->post('http://localhost/logout');
    $t->otp->sent = [];

    return $response->getCookie(TrustedDevices::COOKIE)?->getValue();
}

it('skips the code (not the password) on a trusted device, only for that user and for 30 days', function (): void {
    $cookie = signInTrusting($this);
    expect($cookie)->not->toBeNull()->and(DB::table('trusted_devices')->value('token_hash'))->not->toContain((string) explode('|', (string) $cookie)[1]);

    $this->withCookie(TrustedDevices::COOKIE, (string) $cookie)->post('http://localhost/login', ['login' => 'nurse@sunrise.test', 'password' => 'wrong'])->assertSessionHasErrors('login');
    $this->withCookie(TrustedDevices::COOKIE, (string) $cookie)->post('http://localhost/login', ['login' => 'nurse@sunrise.test', 'password' => 'correct-horse-battery'])->assertRedirect('/workspaces');
    expect($this->otp->sent)->toBe([]);
    $this->assertAuthenticatedAs($this->user);
    $this->post('http://localhost/logout');

    $other = User::factory()->create(['email' => 'other@sunrise.test', 'password' => 'correct-horse-battery']);
    $this->withCookie(TrustedDevices::COOKIE, (string) $cookie)->post('http://localhost/login', ['login' => 'other@sunrise.test', 'password' => 'correct-horse-battery'])->assertRedirect('/login/verify');
    expect($this->otp->sent)->toHaveKey($other->id);

    $this->travel(31)->days();
    $this->otp->sent = [];
    $this->withCookie(TrustedDevices::COOKIE, (string) $cookie)->post('http://localhost/login', ['login' => 'nurse@sunrise.test', 'password' => 'correct-horse-battery'])->assertRedirect('/login/verify');
});

it('never lets super admins skip the code, and forgets devices when removed or on sign-out of other devices', function (): void {
    $admin = User::factory()->create(['email' => 'ops@clinicflow.test', 'password' => 'correct-horse-battery']);
    $admin->forceFill(['is_platform_admin' => true])->save();
    expect(signInTrusting($this, 'ops@clinicflow.test'))->toBeNull();

    $cookie = (string) signInTrusting($this);
    $id = (int) DB::table('trusted_devices')->value('id');
    $this->actingAs($this->user)->postJson("http://localhost/account/security/devices/{$id}/forget")->assertOk();
    $this->post('http://localhost/logout');
    $this->withCookie(TrustedDevices::COOKIE, $cookie)->post('http://localhost/login', ['login' => 'nurse@sunrise.test', 'password' => 'correct-horse-battery'])->assertRedirect('/login/verify');

    signInTrusting($this);
    $this->actingAs($this->user)->postJson('http://localhost/account/security/sign-out-others', ['password' => 'correct-horse-battery'])->assertOk();
    expect(DB::table('trusted_devices')->count())->toBe(0);
});

it('lets the owner require an authenticator app for all staff', function (): void {
    config(['clinicflow.security.require_authenticator_for_admins' => true]);
    $owner = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $owner, StaffRole::Owner);
    $owner->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_confirmed_at' => now()])->save();
    $base = 'http://sunrise.clinicflow.test';

    $this->actingAs($this->user)->get('http://localhost/workspaces')->assertOk();
    $this->actingAs($this->user)->withSession([RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()])->post("{$base}/settings/security", ['required' => true])->assertForbidden();
    $this->flushSession();
    $this->actingAs($owner)->post("{$base}/settings/security", ['required' => true])->assertRedirect("{$base}/confirm-identity");
    $this->actingAs($owner)->withSession([RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()])->post("{$base}/settings/security", ['required' => true])->assertSessionHasNoErrors();
    expect((bool) Provider::query()->find($this->clinic->id)->getAttribute('require_authenticator'))->toBeTrue();
    $this->actingAs($owner)->get("{$base}/settings/security")->assertInertia(fn ($p) => $p->where('required', true)->where('withoutApp', 1));

    $this->flushSession();
    $this->actingAs($this->user)->get('http://localhost/workspaces')->assertRedirect(rtrim((string) config('app.url'), '/').'/account/security');
});
