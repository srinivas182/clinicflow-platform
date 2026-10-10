<?php

use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Security\BotProtection;
use App\Domains\Wallet\Support\WalletSettings;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    WalletSettings::put('security.two_factor_enabled', false);
    // The security policy is on in production; switch it on here so the Cloudflare address can be checked.
    config(['clinicflow.security.csp' => true]);
    $this->user = User::factory()->create(['email' => 'nurse@drbusinessflow.com', 'password' => 'correct-horse-battery']);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function switchBotProtectionOn(): void
{
    WalletSettings::put('security.turnstile_site_key', '1x00000000000000000000AA');
    BotProtection::saveSecret('1x0000000000000000000000000000000AA');
    WalletSettings::put('security.bot_protection_enabled', true);
}

function signIn(object $t, array $extra = []): TestResponse
{
    return $t->post('http://localhost/login', ['login' => 'nurse@drbusinessflow.com', 'password' => 'correct-horse-battery', ...$extra]);
}

it('changes nothing while bot protection is off', function (): void {
    Http::fake();
    $page = $this->get('http://localhost/login');
    $page->assertInertia(fn ($p) => $p->where('botProtection', null));
    expect((string) $page->headers->get('Content-Security-Policy'))->toContain("script-src 'self'")->not->toContain('challenges.cloudflare.com');
    signIn($this)->assertRedirect('/workspaces');
    Http::assertNothingSent();
});

it('requires a passed check on sign-in when it is on', function (): void {
    switchBotProtectionOn();
    Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

    signIn($this)->assertSessionHasErrors('human');
    signIn($this, ['cf-turnstile-response' => 'bad-token'])->assertSessionHasErrors('human');
    signIn($this, ['cf-turnstile-response' => 'good-token'])->assertRedirect('/workspaces');
    Http::assertSent(fn (Request $r) => $r['secret'] === '1x0000000000000000000000000000000AA' && $r['response'] === 'good-token');

    $this->post('http://localhost/logout');
    $page = $this->get('http://localhost/forgot-password');
    $page->assertInertia(fn ($p) => $p->where('botProtection.siteKey', '1x00000000000000000000AA'));
    expect((string) $page->headers->get('Content-Security-Policy'))->toContain('https://challenges.cloudflare.com');
});

it('keeps forms working if Cloudflare cannot be reached', function (): void {
    switchBotProtectionOn();
    Http::fake(fn () => throw new ConnectionException('timeout'));
    signIn($this, ['cf-turnstile-response' => 'any-token'])->assertRedirect('/workspaces');
});

it('protects sign-up, forgot password and the patient portal sign-in', function (): void {
    switchBotProtectionOn();
    Http::fake();
    $this->post('http://localhost/forgot-password', ['email' => 'nurse@drbusinessflow.com'])->assertSessionHasErrors('human');
    $this->post('http://localhost/start', ['name' => 'Test'])->assertSessionHasErrors('human');
    if (config('database.default') === 'mysql') {
        makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
        $this->post('http://sunrise.clinicflow.test/my/login', ['cell' => '0821234567'])->assertSessionHasErrors('human');
    }
});

it('lets a super admin set it up after confirming identity, storing the secret encrypted', function (): void {
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->actingAs($admin)->post('http://localhost/admin/security/bot', ['enabled' => true])->assertRedirect('http://localhost/confirm-identity');

    $confirmed = [RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()];
    $this->actingAs($admin)->withSession($confirmed)->post('http://localhost/admin/security/bot', ['enabled' => true])->assertSessionHasErrors('bot');
    $this->actingAs($admin)->withSession($confirmed)->post('http://localhost/admin/security/bot', ['enabled' => true, 'site_key' => 'site-123', 'secret' => 'secret-456'])->assertSessionHasNoErrors();

    $raw = (string) DB::table('platform_settings')->where('key', 'security.turnstile_secret')->value('value');
    expect($raw)->not->toContain('secret-456')->and(BotProtection::secret())->toBe('secret-456')
        ->and(BotProtection::enabled())->toBeTrue()
        ->and(DB::table('activity_log')->where('description', 'Bot protection switched on')->exists())->toBeTrue();
    // Saving again without a secret keeps the stored one.
    $this->actingAs($admin)->withSession($confirmed)->post('http://localhost/admin/security/bot', ['enabled' => false, 'site_key' => 'site-123'])->assertSessionHasNoErrors();
    expect(BotProtection::secret())->toBe('secret-456');
});
