<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Support\Totp;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
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
    $this->owner = User::factory()->create(['email' => 'owner@sunrise.test', 'password' => 'correct-horse-battery']);
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

/** Sets up the authenticator for the owner and returns [secret, recovery codes]. */
function enrolOwner(object $t): array
{
    $secret = $t->actingAs($t->owner)->postJson('http://localhost/account/security/authenticator')->assertOk()->json('secret');
    $t->actingAs($t->owner)->postJson('http://localhost/account/security/authenticator/confirm', ['code' => '000000'])->assertStatus(422);
    $codes = $t->actingAs($t->owner)->postJson('http://localhost/account/security/authenticator/confirm', ['code' => Totp::code($secret, time())])->assertOk()->json('recoveryCodes');
    auth()->guard('web')->logout();

    return [$secret, $codes];
}

it('sets up an authenticator app with a confirmed code, keeping the secret encrypted', function (): void {
    [$secret, $codes] = enrolOwner($this);
    expect($codes)->toHaveCount(10)->each->toMatch('/^[a-z0-9]{5}-[a-z0-9]{5}$/');
    $row = DB::table('users')->where('id', $this->owner->id)->first();
    expect($row->totp_confirmed_at)->not->toBeNull()->and($row->totp_secret)->not->toContain($secret)->and($row->recovery_codes)->not->toContain($codes[0])
        ->and($this->owner->fresh()->toArray())->not->toHaveKeys(['totp_secret', 'recovery_codes']);
});

it('signs in with the app code instead of an SMS code, refuses reused codes, and accepts each recovery code once', function (): void {
    [$secret, $codes] = enrolOwner($this);
    $login = fn () => $this->post('http://localhost/login', ['login' => 'owner@sunrise.test', 'password' => 'correct-horse-battery'])->assertRedirect('/login/verify');

    $login();
    expect($this->otp->sent)->toBe([]);
    $next = Totp::code($secret, time() + 30);
    $this->post('http://localhost/login/verify', ['code' => $next])->assertRedirect('/workspaces');
    $this->assertAuthenticatedAs($this->owner);
    $this->post('http://localhost/logout');

    $login();
    $this->post('http://localhost/login/verify', ['code' => $next])->assertSessionHasErrors('code');
    $this->post('http://localhost/login/verify', ['code' => strtoupper($codes[0])])->assertRedirect('/workspaces');
    $this->post('http://localhost/logout');

    $login();
    $this->post('http://localhost/login/verify', ['code' => $codes[0]])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('requires an authenticator app for owners (and stops them turning it off), but not for other staff', function (): void {
    config(['clinicflow.security.require_authenticator_for_admins' => true]);
    $receptionist = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $receptionist, StaffRole::Receptionist);
    $this->actingAs($receptionist)->get('http://localhost/workspaces')->assertOk();
    // A different person signs in on a fresh session (the session check signs out a mismatched user, as in real use).
    $this->flushSession();
    $this->actingAs($this->owner)->get('http://localhost/workspaces')->assertRedirect(rtrim((string) config('app.url'), '/').'/account/security');
    $this->actingAs($this->owner)->get('http://localhost/account/security')->assertOk()->assertInertia(fn ($p) => $p->where('required', true)->where('enabled', false));

    [$secret] = enrolOwner($this);
    $this->actingAs($this->owner->fresh())->get('http://localhost/workspaces')->assertOk();
    $this->actingAs($this->owner->fresh())->postJson('http://localhost/account/security/authenticator/disable', ['code' => Totp::code($secret, time() + 30)])->assertStatus(422);
    expect($this->owner->fresh()->totp_confirmed_at)->not->toBeNull();
});
