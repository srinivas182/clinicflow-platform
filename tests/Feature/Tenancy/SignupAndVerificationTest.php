<?php

use App\Domains\Identity\Models\Membership;
use App\Domains\Identity\Models\Staff;
use App\Domains\Platform\Actions\EnforceSubscriptionStatus;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Models\VerificationCheck;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->seed(PackageSeeder::class);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function signupPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sunrise Medical Centre',
        'type' => 'clinic',
        'subdomain' => 'sunrise',
        'package_id' => Package::query()->where('code', 'clinic-standard')->value('id'),
        'owner_name' => 'Dr Sizwe Mthembu',
        'owner_email' => 'sizwe@sunrise.test',
        'owner_phone' => '0827001122',
        'password' => 'sunrise-2026',
        'password_confirmation' => 'sunrise-2026',
        'references' => ['bhf_practice_number' => '0123456', 'owner_hpcsa' => 'MP0654321'],
        'accept_terms' => true,
    ], $overrides);
}

it('signs up a clinic with its own address, trial, checklist and owner', function (): void {
    $this->post('http://localhost/start', signupPayload())->assertRedirect();

    $provider = Provider::query()->sole();
    expect($provider->status)->toBe(ProviderStatus::PendingVerification)
        ->and($provider->domains()->value('domain'))->toBe('sunrise.clinicflow.test')
        ->and($provider->subscription?->status)->toBe(SubscriptionStatus::Trialing)
        ->and($provider->subscription?->trial_ends_at?->isSameDay(now()->addDays(30)))->toBeTrue()
        ->and($provider->verificationChecks()->count())->toBe(4)
        ->and($provider->verificationChecks()->where('type', 'bhf_practice_number')->value('reference'))->toBe('0123456');

    $owner = User::query()->where('email', 'sizwe@sunrise.test')->sole();
    expect(Membership::query()->where('user_id', $owner->id)->value('role'))->toBe('owner');
    $provider->run(fn () => expect(Staff::query()->findOrFail($owner->id)->hasRole('owner'))->toBeTrue());
});

it('makes an independent doctor both owner and prescriber', function (): void {
    $this->post('http://localhost/start', signupPayload([
        'name' => 'Dr Priya Naidoo',
        'type' => 'independent_doctor',
        'subdomain' => 'drnaidoo',
        'package_id' => Package::query()->where('code', 'doctor-solo')->value('id'),
    ]))->assertRedirect();

    $provider = Provider::query()->sole();
    $owner = User::query()->where('email', 'sizwe@sunrise.test')->sole();
    $provider->run(fn () => expect(Staff::query()->findOrFail($owner->id)->hasAllRoles(['owner', 'doctor']))->toBeTrue());
});

it('refuses taken or reserved addresses, wrong packages and existing accounts', function (array $overrides, string $field): void {
    makeProvider('Existing', ProviderType::Clinic, 'taken.clinicflow.test');
    User::factory()->create(['email' => 'used@clinic.test']);

    $this->post('http://localhost/start', signupPayload($overrides))->assertSessionHasErrors($field);
})->with([
    'reserved word' => [['subdomain' => 'admin'], 'subdomain'],
    'already taken' => [['subdomain' => 'taken'], 'subdomain'],
    'existing account' => [['owner_email' => 'used@clinic.test'], 'owner_email'],
    'weak password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
]);

it('refuses a package meant for another provider type', function (): void {
    $this->post('http://localhost/start', signupPayload(['package_id' => Package::query()->where('code', 'lab')->value('id')]))
        ->assertSessionHasErrors('package');
});

it('approves a provider only when every required check is verified', function (): void {
    $this->post('http://localhost/start', signupPayload());
    $provider = Provider::query()->sole();
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();

    $this->actingAs($admin)->post("http://localhost/admin/providers/{$provider->id}/approve")->assertSessionHasErrors('verification');
    expect($provider->fresh()?->status)->toBe(ProviderStatus::PendingVerification);

    $provider->verificationChecks->each(fn (VerificationCheck $c) => $this->actingAs($admin)
        ->post("http://localhost/admin/verification-checks/{$c->id}", ['status' => 'verified'])
        ->assertSessionHasNoErrors());

    $this->actingAs($admin)->post("http://localhost/admin/providers/{$provider->id}/approve")->assertSessionHasNoErrors();
    expect($provider->fresh()?->status)->toBe(ProviderStatus::Trial);
});

it('sets providers read-only after the trial and grace period, never before', function (): void {
    $this->post('http://localhost/start', signupPayload());
    $this->post('http://localhost/start', signupPayload(['subdomain' => 'second', 'owner_email' => 'b@x.test', 'owner_phone' => '0827001133']));
    $byDomain = fn (string $d) => Provider::query()->whereHas('domains', fn ($q) => $q->where('domain', $d))->firstOrFail();
    $expired = $byDomain('sunrise.clinicflow.test');
    $inGrace = $byDomain('second.clinicflow.test');

    Subscription::query()->where('tenant_id', $expired->id)->update(['trial_ends_at' => now()->subDays(8)]);
    Subscription::query()->where('tenant_id', $inGrace->id)->update(['trial_ends_at' => now()->subDays(3)]);

    expect(app(EnforceSubscriptionStatus::class)->handle())->toBe(1)
        ->and($expired->fresh()?->status)->toBe(ProviderStatus::ReadOnly)
        ->and($inGrace->fresh()?->status)->toBe(ProviderStatus::PendingVerification);
});
