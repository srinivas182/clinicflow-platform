<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Support\Totp;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->seed(PackageSeeder::class);
    $this->otp = new class implements OtpSender
    {
        /** @var array<int, string> */
        public array $sent = [];

        public function send(User $user, string $code): void
        {
            $this->sent[$user->id] = $code;
        }
    };
    $this->outbox = new class implements MessageSender
    {
        /** @var list<array{0: string, 1: ?string}> */
        public array $sent = [];

        public function send(string $channel, string $recipient, ?string $subject, string $body): bool
        {
            $this->sent[] = [$recipient, $subject];

            return true;
        }
    };
    $this->app->instance(OtpSender::class, $this->otp);
    $this->app->instance(MessageSender::class, $this->outbox);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-pro')->value('id'),
        'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);
    $this->owner = User::factory()->create(['email' => 'owner@sunrise.test', 'password' => 'correct-horse-battery']);
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('asks for the password again before sensitive actions, for 15 minutes at a time', function (): void {
    $base = 'http://sunrise.clinicflow.test';
    $this->actingAs($this->owner)->post("{$base}/settings/api/keys", ['name' => 'Booking site', 'scopes' => ['availability:read']])->assertRedirect("{$base}/confirm-identity");
    $this->actingAs($this->owner)->postJson("{$base}/reports/export/csv", ['definition' => '{}'])->assertStatus(423)->assertJsonPath('confirm', "{$base}/confirm-identity");
    $this->actingAs($this->owner)->post("{$base}/support/grant", ['hours' => 24])->assertRedirect("{$base}/confirm-identity");
    // Other support actions are not protected (only granting access is).
    $open = $this->actingAs($this->owner)->post("{$base}/support/open", ['subject' => 'Help', 'body' => 'Please help']);
    expect((string) $open->headers->get('Location'))->not->toContain('/confirm-identity');

    $this->actingAs($this->owner)->post("{$base}/confirm-identity", ['secret' => 'wrong-password'])->assertSessionHasErrors('secret');
    $this->actingAs($this->owner)->post("{$base}/confirm-identity", ['secret' => 'correct-horse-battery'])->assertRedirect();
    $this->actingAs($this->owner)->post("{$base}/settings/api/keys", ['name' => 'Booking site', 'scopes' => ['availability:read']])->assertSessionHasNoErrors();
    expect(DB::table('activity_log')->where('description', 'Identity confirmed for a sensitive action')->exists())->toBeTrue();

    $this->travel(16)->minutes();
    $this->actingAs($this->owner)->post("{$base}/settings/api/keys", ['name' => 'Another', 'scopes' => ['availability:read']])->assertRedirect("{$base}/confirm-identity");
});

it('confirms with an authenticator code for staff who use the app', function (): void {
    $secret = Totp::newSecret();
    $this->owner->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();
    $base = 'http://sunrise.clinicflow.test';
    $this->actingAs($this->owner)->get("{$base}/confirm-identity")->assertOk()->assertInertia(fn ($p) => $p->component('Auth/ConfirmIdentity')->where('authenticator', true));
    $this->actingAs($this->owner)->post("{$base}/confirm-identity", ['secret' => 'correct-horse-battery'])->assertSessionHasErrors('secret');
    $this->actingAs($this->owner)->post("{$base}/confirm-identity", ['secret' => Totp::code($secret, time())])->assertSessionHasNoErrors();
});

it('keeps a sign-in history and emails the user about a sign-in from a new device', function (): void {
    $signIn = function (string $agent): void {
        $this->withHeader('User-Agent', $agent)->post('http://localhost/login', ['login' => 'owner@sunrise.test', 'password' => 'correct-horse-battery']);
        $this->withHeader('User-Agent', $agent)->post('http://localhost/login/verify', ['code' => $this->otp->sent[$this->owner->id]])->assertRedirect('/workspaces');
        $this->post('http://localhost/logout');
    };
    $signIn('Mozilla/5.0 (Windows NT 10.0) Chrome/130');
    $signIn('Mozilla/5.0 (Windows NT 10.0) Chrome/130');
    expect($this->outbox->sent)->toBe([]);
    $signIn('Mozilla/5.0 (iPhone) Safari/18');
    expect($this->outbox->sent)->toBe([['owner@sunrise.test', 'New sign-in to your account']])
        ->and(DB::table('login_events')->where('user_id', $this->owner->id)->count())->toBe(3)
        ->and(DB::table('login_events')->where('new_device', true)->count())->toBe(1);
    $this->actingAs($this->owner)->get('http://localhost/account/security')->assertInertia(fn ($p) => $p->has('signIns', 3)->where('signIns.0.newDevice', true));
});

it('signs out other devices with the password, and uses safer session defaults', function (): void {
    $hash = $this->owner->password;
    $this->actingAs($this->owner)->postJson('http://localhost/account/security/sign-out-others', ['password' => 'nope'])->assertStatus(422);
    $this->actingAs($this->owner)->postJson('http://localhost/account/security/sign-out-others', ['password' => 'correct-horse-battery'])->assertOk();
    expect($this->owner->fresh()->password)->not->toBe($hash)
        ->and(config('session.lifetime'))->toBe(30)->and(config('session.encrypt'))->toBeTrue();
});
